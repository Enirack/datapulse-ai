<?php

declare(strict_types=1);

namespace App\Profiling;

interface DataEngineClientInterface
{
    /**
     * @return array<string, mixed> the decoded profiling result (matches
     *                               the contract documented in docs/data-profiling.md)
     *
     * @throws DataEngineException on any non-2xx response or transport failure
     */
    public function profile(string $datasetVersionId, string $storageIdentifier, string $extension): array;
}
