<?php

declare(strict_types=1);

namespace App\Profiling;

/**
 * The single client Symfony uses to talk to the Python data engine —
 * profiling (Phase 3) and cleaning (Phase 4) share it, per the project's
 * "Symfony orchestrates through one DataEngineClient" architecture.
 */
interface DataEngineClientInterface
{
    /**
     * @return array<string, mixed> the decoded profiling result (matches
     *                               the contract documented in docs/data-profiling.md)
     *
     * @throws DataEngineException on any non-2xx response or transport failure
     */
    public function profile(string $datasetVersionId, string $storageIdentifier, string $extension): array;

    /**
     * Applies a cleaning pipeline and, if it actually changes anything,
     * writes the result to the shared tmp area (see docs/data-cleaning.md)
     * — never to permanent storage directly; committing that file into
     * permanent storage is the caller's job via DatasetStorageInterface.
     *
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, mixed> {changed, tempStorageIdentifier?, checksum?,
     *                               fileSize?, mimeType?, report}
     *
     * @throws DataEngineException on any non-2xx response or transport failure
     */
    public function clean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array;

    /**
     * Same pipeline, but never writes anything — returns an estimate of
     * what would change.
     *
     * @param list<array<string, mixed>> $operations
     *
     * @return array<string, mixed> {estimatedAffectedRows, operations, before, after}
     *
     * @throws DataEngineException on any non-2xx response or transport failure
     */
    public function previewClean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array;
}
