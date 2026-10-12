<?php

declare(strict_types=1);

namespace App\Service;

use App\Cleaning\Operation;
use App\Cleaning\PipelineParser;
use App\Entity\Analysis;
use App\Entity\DatasetVersion;
use App\Enum\AnalysisStatus;
use App\Enum\AnalysisType;
use App\Enum\DatasetVersionKind;
use App\Profiling\DataEngineClientInterface;
use App\Profiling\DataEngineException;
use App\Repository\AnalysisRepository;
use App\Repository\DatasetVersionRepository;
use App\Storage\DatasetStorageInterface;
use App\Storage\StorageException;
use App\Storage\TempStorageResolver;
use App\Upload\Validator\CsvValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a cleaning run end to end. The original DatasetVersion is
 * never touched — a successful, data-changing run always produces a new
 * DatasetVersion (kind: Cleaned) pointing back at its source; a run that
 * changes nothing completes without creating one.
 *
 * Idempotency: keyed on sha256(sourceVersion.checksum + canonical pipeline
 * JSON) — see PipelineParser::fingerprint(). By default, re-requesting the
 * exact same pipeline against the exact same source reuses the existing
 * *completed* Analysis instead of re-running anything. $force=true always
 * starts fresh. A prior pending/running/failed analysis for that same
 * fingerprint is never reused, same policy as profiling.
 */
final class CleaningService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalysisRepository $analysisRepository,
        private readonly DatasetVersionRepository $versionRepository,
        private readonly DataEngineClientInterface $dataEngineClient,
        private readonly DatasetStorageInterface $storage,
        private readonly TempStorageResolver $tempStorage,
        private readonly PipelineParser $pipelineParser,
        private readonly CsvValidator $csvValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Operation> $operations already structurally validated by the caller
     */
    public function clean(DatasetVersion $sourceVersion, array $operations, bool $force = false): Analysis
    {
        $operationsArray = array_map(static fn (Operation $op) => $op->toArray(), $operations);
        $fingerprint = $this->pipelineParser->fingerprint($operations, $sourceVersion->getChecksum());

        if (!$force) {
            $existing = $this->analysisRepository->findByFingerprint($sourceVersion, AnalysisType::Cleaning->value, $fingerprint);
            if ($existing !== null && $existing->getStatus() === AnalysisStatus::Completed) {
                return $existing;
            }
        }

        $analysis = new Analysis($sourceVersion, AnalysisType::Cleaning->value);
        $analysis->setPipeline($operationsArray, $fingerprint);
        $this->em->persist($analysis);
        $analysis->markRunning();
        $this->em->flush();

        $extension = strtolower(pathinfo($sourceVersion->getOriginalFilename(), \PATHINFO_EXTENSION));
        $datasetVersionId = (string) $sourceVersion->getId();
        $startedAt = microtime(true);

        $this->logger->info('cleaning.start', [
            'datasetVersionId' => $datasetVersionId,
            'operationCount' => \count($operations),
        ]);

        try {
            $result = $this->dataEngineClient->clean($datasetVersionId, $sourceVersion->getStoragePath(), $extension, $operationsArray);
            $report = \is_array($result['report'] ?? null) ? $result['report'] : [];

            if ((bool) ($result['changed'] ?? false)) {
                $newVersion = $this->commitCleanedFile($sourceVersion, $result, $extension);
                $analysis->markCompletedWithNewVersion($newVersion, $report, '1.0');

                $this->logger->info('cleaning.completed', [
                    'datasetVersionId' => $datasetVersionId,
                    'resultVersionId' => (string) $newVersion->getId(),
                    'changed' => true,
                    'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);
            } else {
                $analysis->markCompleted($report, '1.0');

                $this->logger->info('cleaning.completed', [
                    'datasetVersionId' => $datasetVersionId,
                    'changed' => false,
                    'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);
            }
        } catch (DataEngineException $e) {
            $analysis->markFailed($e->getMessage());

            $this->logger->warning('cleaning.failed', [
                'datasetVersionId' => $datasetVersionId,
                'statusCode' => $e->httpStatusCode,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $analysis->markFailed('Internal error while cleaning the dataset.');

            $this->logger->error('cleaning.failed', [
                'datasetVersionId' => $datasetVersionId,
                'reason' => 'unexpected',
                'exception' => $e,
            ]);
        }

        $this->em->flush();

        return $analysis;
    }

    /**
     * Runs the pipeline against the source version without creating a
     * DatasetVersion or an Analysis — a pure read of "what would happen".
     *
     * @param list<Operation> $operations
     *
     * @return array<string, mixed> {estimatedAffectedRows, operations, before, after}
     */
    public function preview(DatasetVersion $sourceVersion, array $operations): array
    {
        $operationsArray = array_map(static fn (Operation $op) => $op->toArray(), $operations);
        $extension = strtolower(pathinfo($sourceVersion->getOriginalFilename(), \PATHINFO_EXTENSION));

        $this->logger->info('cleaning.preview', [
            'datasetVersionId' => (string) $sourceVersion->getId(),
            'operationCount' => \count($operations),
        ]);

        return $this->dataEngineClient->previewClean(
            (string) $sourceVersion->getId(),
            $sourceVersion->getStoragePath(),
            $extension,
            $operationsArray,
        );
    }

    /**
     * Moves the data engine's tmp result into permanent storage and
     * creates the new DatasetVersion. If anything after the move fails,
     * the just-stored file is deleted so it never becomes an orphan.
     *
     * @param array<string, mixed> $result
     */
    private function commitCleanedFile(DatasetVersion $sourceVersion, array $result, string $extension): DatasetVersion
    {
        $tempIdentifier = \is_string($result['tempStorageIdentifier'] ?? null) ? $result['tempStorageIdentifier'] : '';

        try {
            $absoluteTempPath = $this->tempStorage->resolve($tempIdentifier);
            $storageIdentifier = $this->storage->store($absoluteTempPath, $extension);
        } catch (StorageException $e) {
            throw new \RuntimeException('Failed to commit the cleaned file to permanent storage.', previous: $e);
        }

        try {
            // Recompute checksum/size server-side rather than trusting the
            // data engine's numbers — same authority model as ingestion.
            $finalPath = $this->storage->retrieve($storageIdentifier);
            $checksum = hash_file('sha256', $finalPath);
            $fileSize = filesize($finalPath);
            if ($checksum === false || $fileSize === false) {
                throw new \RuntimeException('Failed to verify the committed cleaned file.');
            }

            $formulaInjectionRisk = $extension === 'csv'
                ? $this->csvValidator->validate($finalPath)->formulaInjectionRisk
                : false;

            $mimeType = \is_string($result['mimeType'] ?? null) ? $result['mimeType'] : $sourceVersion->getMimeType();
            $nextVersionNumber = $this->versionRepository->findMaxVersionNumber($sourceVersion->getDataset()) + 1;

            $newVersion = new DatasetVersion(
                $sourceVersion->getDataset(),
                $nextVersionNumber,
                DatasetVersionKind::Cleaned,
                $sourceVersion->getOriginalFilename(),
                $storageIdentifier,
                $mimeType,
                $fileSize,
                $checksum,
                $formulaInjectionRisk,
                $sourceVersion,
            );
            $this->em->persist($newVersion);

            return $newVersion;
        } catch (\Throwable $e) {
            // Compensating action: don't leave a stored file with no
            // DatasetVersion pointing at it.
            $this->storage->delete($storageIdentifier);

            throw $e;
        }
    }
}
