<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FixtureFiles;

final class AnalysisControllerTest extends ApiTestCase
{
    public function testTriggerRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        // Fresh, unauthenticated request.
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile");

        self::assertResponseStatusCodeSame(401);
    }

    public function testAuthenticatedOwnerCanTriggerProfilingAndGetsCompletedResult(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('completed', $data['status']);
        self::assertSame($versionId, $data['datasetVersionId']);
        self::assertSame('profiling', $data['type']);
        self::assertSame('1.0', $data['resultSchemaVersion']);
        self::assertIsArray($data['result']);
        self::assertNull($data['errorMessage']);
        self::assertNotNull($data['startedAt']);
        self::assertNotNull($data['completedAt']);
    }

    public function testUserCannotTriggerProfilingOnAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $ownerToken = $this->createAuthenticatedUser($client, 'profiling-owner@example.test');
        [$datasetId, $versionId] = $this->uploadDataset($client, $ownerToken);

        $intruderToken = $this->createAuthenticatedUser($client, 'profiling-intruder@example.test');
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($intruderToken));

        self::assertResponseStatusCodeSame(403);
    }

    public function testVersionBelongingToAnotherDatasetIsNotFound(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        [$datasetAId] = $this->uploadDataset($client, $token, 'a.csv');
        [, $versionBId] = $this->uploadDataset($client, $token, 'b.csv');

        // versionB really exists, but not under datasetA.
        $client->request('POST', "/api/datasets/{$datasetAId}/versions/{$versionBId}/profile", server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownDatasetReturns404(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', '/api/datasets/'.self::randomUuid()."/versions/{$versionId}/profile", server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testFailedProfilingIsRecordedWithoutHttpError(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willFail(422, 'File is not in a tabular-convertible shape.');

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));

        // The *request* to trigger profiling succeeded; the *analysis* failed.
        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('failed', $data['status']);
        self::assertSame('File is not in a tabular-convertible shape.', $data['errorMessage']);
        self::assertNull($data['result']);
    }

    public function testIdempotentByDefaultReusesCompletedAnalysis(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, static::fakeDataEngine()->callCount());
    }

    public function testForceTrueAlwaysTriggersAFreshAnalysis(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile?force=true", server: $this->authHeaders($token));
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, static::fakeDataEngine()->callCount());
    }

    public function testShowReturnsLatestAnalysisWithoutTriggering(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404); // nothing profiled yet
        self::assertSame(0, static::fakeDataEngine()->callCount());

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        $triggered = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(200);
        $shown = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame($triggered['id'], $shown['id']);
        self::assertSame(1, static::fakeDataEngine()->callCount()); // GET never triggers
    }

    public function testShowRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/profile");

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array{0: string, 1: string} [datasetId, versionId]
     */
    private function uploadDataset(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, string $filename = 'data.csv'): array
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

    private static function randomUuid(): string
    {
        return \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
    }
}
