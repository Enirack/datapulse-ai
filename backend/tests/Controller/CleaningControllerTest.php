<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\DatasetVersion;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FixtureFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CleaningControllerTest extends ApiTestCase
{
    public function testCleanRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request(
            'POST',
            "/api/datasets/{$datasetId}/versions/{$versionId}/clean",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['operations' => [['type' => 'remove_duplicate_rows']]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testUserCannotCleanAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $ownerToken = $this->createAuthenticatedUser($client, 'clean-owner@example.test');
        [$datasetId, $versionId] = $this->uploadDataset($client, $ownerToken);

        $intruderToken = $this->createAuthenticatedUser($client, 'clean-intruder@example.test');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $intruderToken);

        self::assertResponseStatusCodeSame(403);
    }

    public function testVersionBelongingToAnotherDatasetIsNotFound(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        [$datasetAId] = $this->uploadDataset($client, $token, 'a.csv');
        [, $versionBId] = $this->uploadDataset($client, $token, 'b.csv');

        $this->postJson($client, "/api/datasets/{$datasetAId}/versions/{$versionBId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidPipelineIsRejectedWith400(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'not_a_real_operation']]], $token);
        self::assertResponseStatusCodeSame(400);

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => []], $token);
        self::assertResponseStatusCodeSame(400);

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'trim_whitespace']]], $token);
        self::assertResponseStatusCodeSame(400); // missing required "column"
    }

    public function testValidPipelineCreatesNewVersionWithCorrectLineageAndChecksum(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('completed', $data['status']);
        self::assertSame($versionId, $data['sourceVersionId']);
        self::assertNotNull($data['resultVersionId']);
        self::assertTrue($data['changed']);

        $em = static::getContainer()->get('doctrine')->getManager();
        $newVersion = $em->getRepository(DatasetVersion::class)->find($data['resultVersionId']);

        self::assertNotNull($newVersion);
        self::assertSame('cleaned', $newVersion->getKind()->value);
        self::assertSame($versionId, (string) $newVersion->getSourceVersion()->getId());
        self::assertSame(hash('sha256', "a,b\n1,2\n"), $newVersion->getChecksum());
        self::assertSame(2, $newVersion->getVersionNumber());
    }

    public function testOriginalVersionIsNeverModified(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $em = static::getContainer()->get('doctrine')->getManager();
        $originalChecksum = $em->getRepository(DatasetVersion::class)->find($versionId)->getChecksum();

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);
        self::assertResponseStatusCodeSame(201);

        $em->clear();
        $sourceAfter = $em->getRepository(DatasetVersion::class)->find($versionId);
        self::assertSame($originalChecksum, $sourceAfter->getChecksum());
        self::assertSame(1, $sourceAfter->getVersionNumber());
    }

    public function testUnchangedCleanCreatesNoNewVersion(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanChangeNothing();
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'trim_whitespace', 'column' => 'a']]], $token);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('completed', $data['status']);
        self::assertFalse($data['changed']);
        self::assertNull($data['resultVersionId']);

        $client->request('GET', "/api/datasets/{$datasetId}", server: $this->authHeaders($token));
        $dataset = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $dataset['versions']); // still only the original
    }

    public function testFailedCleanCreatesNoNewVersionAndNoResult(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanFail(422, 'Column "age" does not exist in this dataset.');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'fill_median', 'column' => 'age']]], $token);

        self::assertResponseStatusCodeSame(201); // trigger itself succeeded
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('failed', $data['status']);
        self::assertSame('Column "age" does not exist in this dataset.', $data['errorMessage']);
        self::assertNull($data['resultVersionId']);
        self::assertNull($data['report']);

        $client->request('GET', "/api/datasets/{$datasetId}", server: $this->authHeaders($token));
        $dataset = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $dataset['versions']);
    }

    public function testIdempotentByDefaultReusesCompletedAnalysisForSamePipeline(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame($first['analysisId'], $second['analysisId']);
        self::assertSame(1, static::fakeDataEngine()->cleanCallCount());
    }

    public function testDifferentPipelineIsNotConsideredIdempotentWithPrevious(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n3,4\n", 'csv');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'trim_whitespace', 'column' => 'a']]], $token);

        self::assertSame(2, static::fakeDataEngine()->cleanCallCount());
    }

    public function testForceTrueAlwaysTriggersAFreshRun(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        static::fakeDataEngine()->willCleanChangeContentTo("a,b\n1,2\n", 'csv');
        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean?force=true", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertNotSame($first['analysisId'], $second['analysisId']);
        self::assertSame(2, static::fakeDataEngine()->cleanCallCount());
    }

    public function testPreviewNeverCreatesAVersionOrAnalysis(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        static::fakeDataEngine()->willPreviewReturn([
            'estimatedAffectedRows' => 3,
            'operations' => [],
            'before' => ['rows' => 2, 'columns' => 2],
            'after' => ['rows' => 2, 'columns' => 2],
        ]);

        $this->postJson($client, "/api/datasets/{$datasetId}/versions/{$versionId}/clean/preview", ['operations' => [['type' => 'remove_duplicate_rows']]], $token);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame(3, $data['estimatedAffectedRows']);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/clean", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404); // no cleaning analysis exists

        $client->request('GET', "/api/datasets/{$datasetId}", server: $this->authHeaders($token));
        $dataset = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertCount(1, $dataset['versions']);
    }

    public function testRecommendationsRequireAPriorCompletedProfile(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/cleaning-recommendations", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(201);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/cleaning-recommendations", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('recommendations', $data);
    }

    public function testRecommendationsRequireAuthenticationAndOwnership(): void
    {
        $client = static::createClient();
        $ownerToken = $this->createAuthenticatedUser($client, 'reco-owner@example.test');
        [$datasetId, $versionId] = $this->uploadDataset($client, $ownerToken);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/cleaning-recommendations");
        self::assertResponseStatusCodeSame(401);

        $intruderToken = $this->createAuthenticatedUser($client, 'reco-intruder@example.test');
        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/cleaning-recommendations", server: $this->authHeaders($intruderToken));
        self::assertResponseStatusCodeSame(403);
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

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(KernelBrowser $client, string $uri, array $payload, string $token): void
    {
        $client->request(
            'POST',
            $uri,
            server: array_merge($this->authHeaders($token), ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }
}
