<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FixtureFiles;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AiAnalystControllerTest extends ApiTestCase
{
    public function testTriggerRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/insights");
        self::assertResponseStatusCodeSame(401);
    }

    public function testShowRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/insights");
        self::assertResponseStatusCodeSame(401);
    }

    public function testAskRequiresAuthentication(): void
    {
        $client = static::createClient();
        [$datasetId, $versionId] = $this->uploadDataset($client, $this->createAuthenticatedUser($client));

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/insights/ask", content: json_encode(['question' => 'How many rows?']));
        self::assertResponseStatusCodeSame(401);
    }

    public function testUserCannotAccessAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $ownerToken = $this->createAuthenticatedUser($client, 'ai-owner@example.test');
        [$datasetId, $versionId] = $this->uploadDataset($client, $ownerToken);

        $intruderToken = $this->createAuthenticatedUser($client, 'ai-intruder@example.test');
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/insights", server: $this->authHeaders($intruderToken));
        self::assertResponseStatusCodeSame(403);
    }

    public function testShowWithoutAPriorRunReturns404(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/insights", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTriggerWithoutProfileOrAnalyticsFailsWith422(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/insights", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(422);
    }

    public function testTriggerSucceedsWhenProfileAndAnalyticsExist(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        [$datasetId, $versionId] = $this->uploadDataset($client, $token);

        // 1. Trigger profile
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/profile", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(201);

        // 2. Trigger analytics
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/analytics", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(201);

        // 3. Trigger AI insights
        $client->request('POST', "/api/datasets/{$datasetId}/versions/{$versionId}/insights", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(201);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('ai_insights', $data['type']);
        self::assertSame('completed', $data['status']);
        self::assertNotNull($data['result']['executiveSummary']);
        self::assertGreaterThanOrEqual(10, $data['result']['healthScore']);
        self::assertNotEmpty($data['result']['insights']);

        // 4. GET /insights returns the cached result
        $client->request('GET', "/api/datasets/{$datasetId}/versions/{$versionId}/insights", server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(200);

        // 5. POST /insights/ask returns grounded answer
        $client->request(
            'POST',
            "/api/datasets/{$datasetId}/versions/{$versionId}/insights/ask",
            server: array_merge($this->authHeaders($token), ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['question' => 'Are there any correlations?'], \JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(200);

        $askData = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($askData['grounded']);
        self::assertNotEmpty($askData['answer']);
    }

    private function uploadDataset(KernelBrowser $client, string $token, string $filename = 'sample.csv'): array
    {
        $client->request(
            'POST',
            '/api/datasets',
            parameters: ['name' => $filename],
            files: ['file' => FixtureFiles::smallCsv($filename)],
            server: $this->authHeaders($token),
        );

        $data = json_decode($client->getResponse()->getContent(), true);

        return [$data['id'], $data['versions'][0]['id']];
    }
}
