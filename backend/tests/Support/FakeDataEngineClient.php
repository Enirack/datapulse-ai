<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Profiling\DataEngineClientInterface;
use App\Profiling\DataEngineException;

/**
 * Test double for the data engine HTTP call. Bound in place of
 * HttpDataEngineClient only `when@test` (see config/services.yaml) —
 * a natively-run PHPUnit process has no access to the Docker-shared
 * storage volume a real call would need to read from.
 */
final class FakeDataEngineClient implements DataEngineClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $nextSuccessResult = null;
    private ?DataEngineException $nextFailure = null;

    /** @var list<array{datasetVersionId: string, storageIdentifier: string, extension: string}> */
    private array $calls = [];

    /**
     * @param array<string, mixed> $result
     */
    public function willReturnSuccess(array $result): void
    {
        $this->nextSuccessResult = $result;
        $this->nextFailure = null;
    }

    public function willFail(int $statusCode, string $message): void
    {
        $this->nextFailure = DataEngineException::fromResponse($statusCode, $message);
        $this->nextSuccessResult = null;
    }

    public function reset(): void
    {
        $this->nextSuccessResult = null;
        $this->nextFailure = null;
        $this->calls = [];
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }

    public function profile(string $datasetVersionId, string $storageIdentifier, string $extension): array
    {
        $this->calls[] = [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
        ];

        if ($this->nextFailure !== null) {
            throw $this->nextFailure;
        }

        return $this->nextSuccessResult ?? $this->defaultSuccessResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSuccessResult(): array
    {
        return [
            'schemaVersion' => '1.0',
            'dataset' => [
                'rows' => 3,
                'columns' => 1,
                'totalCells' => 3,
                'totalNullCells' => 0,
                'nullPercentage' => 0.0,
                'duplicateRowCount' => 0,
                'duplicateRowPercentage' => 0.0,
            ],
            'columns' => [
                [
                    'name' => 'value',
                    'pandasType' => 'int64',
                    'detectedType' => 'integer',
                    'nullable' => false,
                    'nullCount' => 0,
                    'nullPercentage' => 0.0,
                    'uniqueCount' => 3,
                    'uniquePercentage' => 100.0,
                    'duplicateCount' => 0,
                    'statistics' => [
                        'min' => 1, 'max' => 3, 'mean' => 2.0, 'median' => 2.0,
                        'std' => 1.0, 'variance' => 1.0, 'q25' => 1.5, 'q50' => 2.0, 'q75' => 2.5,
                    ],
                ],
            ],
        ];
    }
}
