<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestCase;

final class AuthControllerTest extends ApiTestCase
{
    public function testRegisterCreatesUserWithoutExposingPassword(): void
    {
        $client = static::createClient();

        $data = $this->registerUser($client, 'alice@example.test', 'correct-horse-battery');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('alice@example.test', $data['email']);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('passwordHash', $data);
        self::assertArrayHasKey('id', $data);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'bob@example.test', 'correct-horse-battery');
        $this->registerUser($client, 'bob@example.test', 'another-password');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterRejectsWeakPassword(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'carol@example.test', 'short');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterRejectsInvalidEmail(): void
    {
        $client = static::createClient();

        $this->registerUser($client, 'not-an-email', 'correct-horse-battery');

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterRejectsMalformedJsonBodyWith400(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not valid json',
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true, \JSON_THROW_ON_ERROR);
        self::assertSame('Invalid JSON body.', $data['message']);
    }

    public function testLoginReturnsJwtToken(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'dave@example.test', 'correct-horse-battery');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'dave@example.test', 'password' => 'correct-horse-battery'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'erin@example.test', 'correct-horse-battery');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'erin@example.test', 'password' => 'wrong-password'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('error', $data['status']);
    }

    public function testMeReturnsCurrentUserWhenAuthenticated(): void
    {
        $client = static::createClient();
        $token = $this->createAuthenticatedUser($client, 'frank@example.test');

        $client->request('GET', '/api/me', server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('frank@example.test', $data['email']);
        self::assertArrayNotHasKey('password', $data);
    }

    public function testMeRejectsUnauthenticatedRequest(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeRejectsInvalidToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-real-token']);

        self::assertResponseStatusCodeSame(401);
    }
}
