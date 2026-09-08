<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\FixtureFiles;

final class DatasetControllerTest extends ApiTestCase
{
    public function testUploadRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/datasets', files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testUploadValidCsv(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("name,age\nAlice,30\nBob,25\n", 'people.csv')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('ready', $data['status']);
        self::assertCount(1, $data['versions']);
        self::assertSame('people.csv', $data['versions'][0]['originalFilename']);
        self::assertSame(1, $data['versions'][0]['versionNumber']);
        self::assertFalse($data['versions'][0]['hasFormulaInjectionRisk']);
    }

    public function testUploadValidXlsx(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::xlsx('workbook.xlsx')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('ready', $data['status']);
        self::assertSame('workbook.xlsx', $data['versions'][0]['originalFilename']);
    }

    public function testUploadValidJson(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::json('[{"a": 1}, {"a": 2}]', 'data.json')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('ready', $data['status']);
    }

    public function testUploadRejectsUnsupportedExtension(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::fromContent('#!/bin/sh\necho hi', 'script.sh', 'text/x-shellscript')],
        );

        self::assertResponseStatusCodeSame(415);
    }

    public function testUploadRejectsIncorrectMimeType(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        // PNG magic bytes, but named as a .csv file.
        $pngBytes = "\x89PNG\r\n\x1a\n".random_bytes(64);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::fromContent($pngBytes, 'fake.csv', 'text/csv')],
        );

        self::assertResponseStatusCodeSame(415);
    }

    public function testUploadRejectsOversizedFile(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $maxSize = (int) static::getContainer()->getParameter('app.max_upload_size');
        $oversized = str_repeat('a', $maxSize + 1024);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv($oversized, 'big.csv')],
        );

        self::assertResponseStatusCodeSame(413);
    }

    public function testUploadRejectsMalformedCsv(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv('', 'empty.csv')],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRejectsMalformedJson(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::json('{"a": [1, 2,', 'broken.json')],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRejectsInvalidXlsxMissingWorkbookParts(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::nonWorkbookZip('notreal.xlsx')],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRejectsFileThatIsNotActuallyAZip(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::corruptXlsx()],
        );

        self::assertResponseStatusCodeSame(415);
    }

    public function testUploadRejectsMaliciousFilenameWithNullByte(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("a,b\n1,2\n", "evil.csv\0.php")],
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testPathTraversalAttemptNeverAffectsStoredFilenameOrPath(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        // Symfony's UploadedFile already reduces this to a bare filename
        // before our code sees it (File::getName() strips everything up to
        // the last "/"), so ingestion succeeds — the key security property
        // under test is that no path segment ever survives into metadata
        // or the storage identifier. FilenameSanitizerTest separately
        // proves our own rejection logic for a raw ".." string.
        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("a,b\n1,2\n", '../../etc/passwd.csv')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame('passwd.csv', $data['versions'][0]['originalFilename']);

        $em = static::getContainer()->get('doctrine')->getManager();
        $version = $em->getRepository(\App\Entity\DatasetVersion::class)->find($data['versions'][0]['id']);
        self::assertStringNotContainsString('..', $version->getStoragePath());
        self::assertStringNotContainsString('etc/passwd', $version->getStoragePath());
    }

    public function testUploadDetectsDuplicateChecksum(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        $content = "a,b\n1,2\n";

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv($content, 'first.csv')]);
        $first = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv($content, 'second.csv')]);
        self::assertResponseStatusCodeSame(201);
        $second = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame($first['id'], $second['possibleDuplicateOfDatasetId']);
        self::assertSame($first['versions'][0]['checksum'], $second['versions'][0]['checksum']);
    }

    public function testUploadDetectsCsvFormulaInjection(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("name,note\nBob,=cmd|'/c calc'!A1\n", 'malicious.csv')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertTrue($data['versions'][0]['hasFormulaInjectionRisk']);
    }

    public function testUploadDoesNotFlagPlainNegativeNumbersAsFormulas(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request(
            'POST',
            '/api/datasets',
            server: $this->authHeaders($token),
            files: ['file' => FixtureFiles::csv("name,balance\nBob,-42.5\n", 'balances.csv')],
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertFalse($data['versions'][0]['hasFormulaInjectionRisk']);
    }

    public function testResponseNeverExposesStoragePath(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);
        $body = $client->getResponse()->getContent();

        self::assertStringNotContainsString('storagePath', $body);
        self::assertStringNotContainsString('var/storage', $body);
    }

    public function testChecksumMatchesUploadedContent(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);
        $content = "a,b\n1,2\n";

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv($content)]);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertSame(hash('sha256', $content), $data['versions'][0]['checksum']);
    }

    public function testListReturnsOnlyOwnDatasets(): void
    {
        $client = static::createClient();
        $tokenA = $this->createAuthenticatedUser($client, 'listera@example.test');
        $client->request('POST', '/api/datasets', server: $this->authHeaders($tokenA), files: ['file' => FixtureFiles::csv("a,b\n1,2\n", 'a.csv')]);

        $tokenB = $this->createAuthenticatedUser($client, 'listerb@example.test');
        $client->request('POST', '/api/datasets', server: $this->authHeaders($tokenB), files: ['file' => FixtureFiles::csv("a,b\n1,2\n", 'b.csv')]);

        $client->request('GET', '/api/datasets', server: $this->authHeaders($tokenA));
        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertCount(1, $data['items']);
        self::assertSame('a.csv', $data['items'][0]['versions'][0]['originalFilename']);
    }

    public function testListIsPaginated(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        foreach (range(1, 3) as $i) {
            $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv("a,b\n1,2\n", "file{$i}.csv")]);
        }

        $client->request('GET', '/api/datasets?page=1&limit=2', server: $this->authHeaders($token));
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        self::assertCount(2, $data['items']);
        self::assertSame(3, $data['pagination']['total']);
        self::assertSame(2, $data['pagination']['totalPages']);
    }

    public function testShowReturnsDatasetWithVersions(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);
        $created = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $client->request('GET', '/api/datasets/'.$created['id'], server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame($created['id'], $data['id']);
        self::assertCount(1, $data['versions']);
    }

    public function testShowReturns404ForNonexistentDataset(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request('GET', '/api/datasets/'.self::randomUuid(), server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUserCannotAccessAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $tokenA = $this->createAuthenticatedUser($client, 'owner@example.test');
        $client->request('POST', '/api/datasets', server: $this->authHeaders($tokenA), files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);
        $created = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $tokenB = $this->createAuthenticatedUser($client, 'intruder@example.test');
        $client->request('GET', '/api/datasets/'.$created['id'], server: $this->authHeaders($tokenB));

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCannotDeleteAnotherUsersDataset(): void
    {
        $client = static::createClient();
        $tokenA = $this->createAuthenticatedUser($client, 'owner2@example.test');
        $client->request('POST', '/api/datasets', server: $this->authHeaders($tokenA), files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);
        $created = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $tokenB = $this->createAuthenticatedUser($client, 'intruder2@example.test');
        $client->request('DELETE', '/api/datasets/'.$created['id'], server: $this->authHeaders($tokenB));

        self::assertResponseStatusCodeSame(403);

        // Confirm it's untouched from the owner's perspective.
        $client->request('GET', '/api/datasets/'.$created['id'], server: $this->authHeaders($tokenA));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDeleteRemovesDatasetAndStoredFile(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client);

        $client->request('POST', '/api/datasets', server: $this->authHeaders($token), files: ['file' => FixtureFiles::csv("a,b\n1,2\n")]);
        $created = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);

        $storage = static::getContainer()->get(\App\Storage\DatasetStorageInterface::class);
        $em = static::getContainer()->get('doctrine')->getManager();
        $version = $em->getRepository(\App\Entity\DatasetVersion::class)->find($created['versions'][0]['id']);
        $storagePath = $version->getStoragePath();
        self::assertTrue($storage->exists($storagePath));

        $client->request('DELETE', '/api/datasets/'.$created['id'], server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(204);

        self::assertFalse($storage->exists($storagePath));

        $client->request('GET', '/api/datasets/'.$created['id'], server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testStorageRootIsOutsideThePublicDirectory(): void
    {
        $storageDir = static::getContainer()->getParameter('app.dataset_storage_dir');
        $publicDir = static::getContainer()->getParameter('kernel.project_dir').'/public';

        self::assertStringNotContainsString($publicDir, (string) $storageDir);
    }

    private static function randomUuid(): string
    {
        return \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
    }
}
