<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    /**
     * Boots the kernel (as usual) and truncates all application tables
     * immediately after, so every test starts from a clean database
     * without needing per-test transactions.
     */
    protected static function createClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = parent::createClient($options, $server);

        static::getContainer()->get('doctrine')->getConnection()->executeStatement(
            'TRUNCATE TABLE dataset_versions, datasets, analyses, insights, reports, users RESTART IDENTITY CASCADE',
        );

        return $client;
    }

    /**
     * @return array<string, mixed> the decoded registration response body
     */
    protected function registerUser(KernelBrowser $client, string $email, string $password): array
    {
        $client->request(
            'POST',
            '/api/auth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        return json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    protected function loginUser(KernelBrowser $client, string $email, string $password): string
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $password], \JSON_THROW_ON_ERROR),
        );

        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $data['token'];
    }

    /**
     * Registers + logs in a fresh user in one call and returns their JWT.
     */
    protected function createAuthenticatedUser(KernelBrowser $client, ?string $email = null, string $password = 'correct-horse-battery'): string
    {
        $email ??= sprintf('user-%s@example.test', bin2hex(random_bytes(6)));
        $this->registerUser($client, $email, $password);

        return $this->loginUser($client, $email, $password);
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }
}
