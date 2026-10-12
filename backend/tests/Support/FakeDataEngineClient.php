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
 *
 * For "clean" it still writes a *real* file under the configured tmp
 * dir when simulating a change, so CleaningService's real
 * DatasetStorageInterface::store() commit is exercised genuinely in
 * tests — only the network/Python boundary is faked.
 */
final class FakeDataEngineClient implements DataEngineClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $nextSuccessResult = null;
    private ?DataEngineException $nextFailure = null;

    /** @var array<string, mixed>|null */
    private ?array $nextCleanResult = null;
    private ?DataEngineException $nextCleanFailure = null;

    /** @var array<string, mixed>|null */
    private ?array $nextPreviewResult = null;

    /** @var array<string, mixed>|null */
    private ?array $nextAnalyticsResult = null;
    private ?DataEngineException $nextAnalyticsFailure = null;

    /** @var list<array{datasetVersionId: string, storageIdentifier: string, extension: string}> */
    private array $calls = [];

    /** @var list<array{datasetVersionId: string, storageIdentifier: string, extension: string, operations: mixed}> */
    private array $cleanCalls = [];

    /** @var list<array{datasetVersionId: string, storageIdentifier: string, extension: string}> */
    private array $analyticsCalls = [];

    public function __construct(private readonly string $tmpDir)
    {
    }

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

    /**
     * Simulates a cleaning run that changes the data: writes $content to a
     * real file under the tmp dir and configures the response to match it.
     *
     * @param array<int, array<string, mixed>> $operationReports
     */
    public function willCleanChangeContentTo(string $content, string $extension = 'csv', array $operationReports = []): void
    {
        $identifier = $this->writeTempFile($content, $extension);

        $this->nextCleanResult = [
            'changed' => true,
            'tempStorageIdentifier' => $identifier,
            'checksum' => hash('sha256', $content),
            'fileSize' => \strlen($content),
            'mimeType' => 'text/csv',
            'report' => [
                'operations' => $operationReports,
                'before' => ['rows' => 0, 'columns' => 0],
                'after' => ['rows' => 0, 'columns' => 0],
            ],
        ];
        $this->nextCleanFailure = null;
    }

    public function willCleanChangeNothing(): void
    {
        $this->nextCleanResult = [
            'changed' => false,
            'report' => [
                'operations' => [],
                'before' => ['rows' => 0, 'columns' => 0],
                'after' => ['rows' => 0, 'columns' => 0],
            ],
        ];
        $this->nextCleanFailure = null;
    }

    public function willCleanFail(int $statusCode, string $message): void
    {
        $this->nextCleanFailure = DataEngineException::fromResponse($statusCode, $message);
        $this->nextCleanResult = null;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function willPreviewReturn(array $result): void
    {
        $this->nextPreviewResult = $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    public function willAnalyzeReturn(array $result): void
    {
        $this->nextAnalyticsResult = $result;
        $this->nextAnalyticsFailure = null;
    }

    public function willAnalyzeFail(int $statusCode, string $message): void
    {
        $this->nextAnalyticsFailure = DataEngineException::fromResponse($statusCode, $message);
        $this->nextAnalyticsResult = null;
    }

    public function reset(): void
    {
        $this->nextSuccessResult = null;
        $this->nextFailure = null;
        $this->nextCleanResult = null;
        $this->nextCleanFailure = null;
        $this->nextPreviewResult = null;
        $this->nextAnalyticsResult = null;
        $this->nextAnalyticsFailure = null;
        $this->calls = [];
        $this->cleanCalls = [];
        $this->analyticsCalls = [];
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }

    public function cleanCallCount(): int
    {
        return \count($this->cleanCalls);
    }

    public function analyticsCallCount(): int
    {
        return \count($this->analyticsCalls);
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

    public function clean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array
    {
        $this->cleanCalls[] = [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
            'operations' => $operations,
        ];

        if ($this->nextCleanFailure !== null) {
            throw $this->nextCleanFailure;
        }

        return $this->nextCleanResult ?? $this->defaultCleanResult();
    }

    public function previewClean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array
    {
        return $this->nextPreviewResult ?? [
            'estimatedAffectedRows' => 0,
            'operations' => [],
            'before' => ['rows' => 0, 'columns' => 0],
            'after' => ['rows' => 0, 'columns' => 0],
        ];
    }

    public function analyze(string $datasetVersionId, string $storageIdentifier, string $extension): array
    {
        $this->analyticsCalls[] = [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
        ];

        if ($this->nextAnalyticsFailure !== null) {
            throw $this->nextAnalyticsFailure;
        }

        return $this->nextAnalyticsResult ?? $this->defaultAnalyticsResult();
    }

    private function writeTempFile(string $content, string $extension): string
    {
        $uuid = bin2hex(random_bytes(16));
        $shard = substr($uuid, 0, 2);
        $dir = rtrim($this->tmpDir, '/\\').'/'.$shard;
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        $identifier = $shard.'/'.$uuid.'.'.$extension;
        file_put_contents(rtrim($this->tmpDir, '/\\').'/'.$identifier, $content);

        return $identifier;
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

    /**
     * @return array<string, mixed>
     */
    private function defaultCleanResult(): array
    {
        return [
            'changed' => false,
            'report' => [
                'operations' => [],
                'before' => ['rows' => 0, 'columns' => 0],
                'after' => ['rows' => 0, 'columns' => 0],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultAnalyticsResult(): array
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
            'summary' => [
                'numericColumnCount' => 1,
                'categoricalColumnCount' => 0,
                'temporalColumnCount' => 0,
                'unanalyzedColumnCount' => 0,
                'chartCount' => 2,
            ],
            'numeric' => [
                [
                    'column' => 'value',
                    'statistics' => [
                        'count' => 3, 'missingCount' => 0, 'missingPercentage' => 0.0, 'uniqueCount' => 3,
                        'min' => 1.0, 'max' => 3.0, 'mean' => 2.0, 'median' => 2.0,
                        'standardDeviation' => 1.0, 'variance' => 1.0, 'q1' => 1.5, 'q3' => 2.5, 'iqr' => 1.0,
                        'sum' => 6.0, 'range' => 2.0,
                    ],
                    'histogram' => ['type' => 'histogram', 'column' => 'value', 'bins' => [1.0, 2.0, 3.0], 'counts' => [1, 2]],
                    'boxplot' => [
                        'type' => 'boxplot', 'column' => 'value', 'min' => 1.0, 'q1' => 1.5, 'median' => 2.0,
                        'q3' => 2.5, 'max' => 3.0, 'lowerFence' => 0.0, 'upperFence' => 4.0, 'outlierCount' => 0,
                    ],
                ],
            ],
            'categorical' => [],
            'temporal' => [],
            'correlations' => null,
            'scatter' => [],
            'charts' => [],
        ];
    }
}
