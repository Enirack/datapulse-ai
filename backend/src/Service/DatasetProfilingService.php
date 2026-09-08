<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Analysis;
use App\Entity\DatasetVersion;
use App\Enum\AnalysisStatus;
use App\Enum\AnalysisType;
use App\Profiling\DataEngineClientInterface;
use App\Profiling\DataEngineException;
use App\Repository\AnalysisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotency: by default, a request to profile a DatasetVersion that
 * already has a *completed* profiling Analysis returns that existing
 * result instead of re-running anything. Passing $force=true always
 * starts a fresh Analysis. A prior pending/running/failed Analysis is
 * never reused — the request always retries in that case. There's no
 * cross-request locking (no job queue in this phase), so two concurrent
 * requests for the same never-yet-profiled version can each start a run;
 * documented as a known limitation rather than solved with infrastructure
 * this phase doesn't otherwise need.
 */
final class DatasetProfilingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalysisRepository $analysisRepository,
        private readonly DataEngineClientInterface $dataEngineClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function profile(DatasetVersion $version, bool $force = false): Analysis
    {
        if (!$force) {
            $existing = $this->analysisRepository->findLatestForVersion($version, AnalysisType::Profiling->value);
            if ($existing !== null && $existing->getStatus() === AnalysisStatus::Completed) {
                return $existing;
            }
        }

        $analysis = new Analysis($version, AnalysisType::Profiling->value);
        $this->em->persist($analysis);
        $analysis->markRunning();
        $this->em->flush();

        $extension = strtolower(pathinfo($version->getOriginalFilename(), \PATHINFO_EXTENSION));
        $datasetVersionId = (string) $version->getId();
        $startedAt = microtime(true);

        $this->logger->info('profiling.start', ['datasetVersionId' => $datasetVersionId, 'extension' => $extension]);

        try {
            $result = $this->dataEngineClient->profile($datasetVersionId, $version->getStoragePath(), $extension);
            $schemaVersion = \is_string($result['schemaVersion'] ?? null) ? $result['schemaVersion'] : 'unknown';

            $analysis->markCompleted($result, $schemaVersion);

            $this->logger->info('profiling.completed', [
                'datasetVersionId' => $datasetVersionId,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'rows' => $result['dataset']['rows'] ?? null,
                'columns' => $result['dataset']['columns'] ?? null,
            ]);
        } catch (DataEngineException $e) {
            $analysis->markFailed($e->getMessage());

            $this->logger->warning('profiling.failed', [
                'datasetVersionId' => $datasetVersionId,
                'statusCode' => $e->httpStatusCode,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $analysis->markFailed('Internal error while profiling the dataset.');

            $this->logger->error('profiling.failed', [
                'datasetVersionId' => $datasetVersionId,
                'reason' => 'unexpected',
                'exception' => $e,
            ]);
        }

        $this->em->flush();

        return $analysis;
    }
}
