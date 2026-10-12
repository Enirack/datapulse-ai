<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FixtureFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AnalyticsControllerTest extends ApiTestCase
{
    public function testTriggerRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics");
        self::assertResponseStatusCodeSame(401);
    }

    public function testShowRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics");
        self::assertResponseStatusCodeSame(401);
    }

    public function testUserCannotAnalyzeAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $ownerToken = $this->createAuthenticatedUser($client, 'analytics-owner@example.test');
        [$datasetId, $versionId] = $this->uploadDataset($client, $ownerToken);

        $intruderToken = $this->createAuthenticatedUser($client, 'analytics-intruder@example.test');
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($intruderToken));

        self::assertResponseStatusCodeSame(403);
    }

    public function testVersionBelongingToAnotherDatasetIsNotFound(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        [$datasetAId] = $this->uploadDataset($client, $token, 'a.csv');
        [, $versionBId] = $this->uploadDataset($client, $token, 'b.csv');

        $client->request('POST', "/api/datasets/{$datasetAId}/versions/{$versionBId}/analytics", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testShowWithoutAPriorRunReturns404(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTriggerPersistsAndReturnsACompletedAnalysis(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('analytics', $data['type']);
        self::assertSame('completed', $data['status']);
        self::assertSame($versionId, $data['datasetVersionId']);
        self::assertSame('1.0', $data['resultSchemaVersion']);
        self::assertArrayHasKey('summary', $data['result']);
        self::assertArrayHasKey('numeric', $data['result']);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(200);
        $shown = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame($data['id'], $shown['id']);
    }

    public function testFailedAnalyticsRunIsPersistedWithErrorMessage(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willAnalyzeFail(413, 'File is too large to analyze.');
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(201); // trigger itself succeeded, the analysis recorded a failure
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('failed', $data['status']);
        self::assertSame('File is too large to analyze.', $data['errorMessage']);
        self::assertNull($data['result']);
    }

    public function testIdempotentByDefaultReusesCompletedAnalysisForSameVersion(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, static::fakeDataEngine()->analyticsCallCount());
    }

    public function testForceTrueAlwaysTriggersAFreshRun(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics?force=true", server: $this->authHeaders($token));
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, static::fakeDataEngine()->analyticsCallCount());
    }

    public function testDataEngineFailureDoesNotExposeInternalDetails(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willAnalyzeFail(500, 'Internal error while analyzing the dataset.');
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));

        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('failed', $data['status']);
        self::assertStringNotContainsString('/app/', (string) $data['errorMessage']);
        self::assertStringNotContainsString('Exception', (string) $data['errorMessage']);
    }

    /**
     * @return array{0: string, 1: string} [datasetId, versionId]
     */
    private function uploadDataset(KernelBrowser $client, string $token, string $filename = 'data.csv'): array
    {
        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("a,b\n1,2\n3,4\n", $filename)],
        );

        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        return [$data['id'], $data['versions'][0]['id']];
    }
}
