<?php

declare(strict_types=1);

namespace App\Profiling;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Only ever sends an identifier + storage reference — never the file
 * itself. Symfony and the data engine share the same underlying storage
 * volume (see docker-compose.yml), so the data engine reads (and, for
 * cleaning, writes a tmp result) directly rather than exchanging bytes
 * over HTTP.
 */
final class HttpDataEngineClient implements DataEngineClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $dataEngineBaseUrl,
    ) {
    }

    public function profile(string $datasetVersionId, string $storageIdentifier, string $extension): array
    {
        return $this->request('/internal/profile', [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
        ]);
    }

    public function clean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array
    {
        return $this->request('/internal/clean', [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
            'operations' => $operations,
        ], timeout: 60);
    }

    public function previewClean(string $datasetVersionId, string $storageIdentifier, string $extension, array $operations): array
    {
        return $this->request('/internal/clean/preview', [
            'datasetVersionId' => $datasetVersionId,
            'storageIdentifier' => $storageIdentifier,
            'extension' => $extension,
            'operations' => $operations,
        ], timeout: 60);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $payload, int $timeout = 30): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim($this->dataEngineBaseUrl, '/').$path, [
                'json' => $payload,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->toArray(false);
        } catch (HttpClientExceptionInterface) {
            throw DataEngineException::communicationFailure();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = \is_array($content) && \is_string($content['message'] ?? null)
                ? $content['message']
                : 'Data engine returned an error.';

            throw DataEngineException::fromResponse($statusCode, $message);
        }

        if (!\is_array($content)) {
            throw DataEngineException::unexpectedResponse();
        }

        return $content;
    }
}
