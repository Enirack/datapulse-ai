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
 * Orchestrates an analytics run. Purely read-only against the dataset
 * version's stored file — unlike cleaning, it never produces a new
 * DatasetVersion.
 *
 * Idempotency: keyed on sha256(sourceVersion.checksum + schemaVersion) —
 * see fingerprint(). There is no client-supplied configuration yet (column
 * selection is fully automatic, see docs/data-analytics.md "Automatic
 * analysis selection"), so the schema version alone stands in for "the
 * exact configuration this analysis was run with"; a future version that
 * adds selectable parameters would fold their canonical JSON into this
 * same fingerprint, exactly as PipelineParser::fingerprint() does for
 * cleaning. By default, re-requesting analytics for a version whose
 * *completed* analysis already has a matching fingerprint reuses it
 * instead of re-running anything. $force=true always starts fresh.
 */
final class AnalyticsService
{
    /**
     * Mirrors the data engine's `analytics_schema_version` setting
     * (app/config.py). Bumping either one (in lockstep) invalidates
     * previously cached results, since it's part of the fingerprint.
     */
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalysisRepository $analysisRepository,
        private readonly DataEngineClientInterface $dataEngineClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function analyze(DatasetVersion $version, bool $force = false): Analysis
    {
        $fingerprint = self::fingerprint($version->getChecksum());

        if (!$force) {
            $existing = $this->analysisRepository->findByFingerprint($version, AnalysisType::Analytics->value, $fingerprint);
            if ($existing !== null && $existing->getStatus() === AnalysisStatus::Completed) {
                return $existing;
            }
        }

        $analysis = new Analysis($version, AnalysisType::Analytics->value);
        $analysis->setPipeline([], $fingerprint);
        $this->em->persist($analysis);
        $analysis->markRunning();
        $this->em->flush();

        $extension = strtolower(pathinfo($version->getOriginalFilename(), \PATHINFO_EXTENSION));
        $datasetVersionId = (string) $version->getId();
        $startedAt = microtime(true);

        $this->logger->info('analytics.start', ['datasetVersionId' => $datasetVersionId, 'extension' => $extension]);

        try {
            $result = $this->dataEngineClient->analyze($datasetVersionId, $version->getStoragePath(), $extension);
            $schemaVersion = \is_string($result['schemaVersion'] ?? null) ? $result['schemaVersion'] : self::SCHEMA_VERSION;

            $analysis->markCompleted($result, $schemaVersion);

            $this->logger->info('analytics.completed', [
                'datasetVersionId' => $datasetVersionId,
                'durationMs' => (int) ((microtime(true) - $startedAt) * 1000),
                'chartCount' => $result['summary']['chartCount'] ?? null,
            ]);
        } catch (DataEngineException $e) {
            $analysis->markFailed($e->getMessage());

            $this->logger->warning('analytics.failed', [
                'datasetVersionId' => $datasetVersionId,
                'statusCode' => $e->httpStatusCode,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $analysis->markFailed('Internal error while analyzing the dataset.');

            $this->logger->error('analytics.failed', [
                'datasetVersionId' => $datasetVersionId,
                'reason' => 'unexpected',
                'exception' => $e,
            ]);
        }

        $this->em->flush();

        return $analysis;
    }

    private static function fingerprint(string $sourceChecksum): string
    {
        return hash('sha256', $sourceChecksum.'|'.self::SCHEMA_VERSION);
    }
}
