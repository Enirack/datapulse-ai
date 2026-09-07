<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiExceptionListenerTest extends WebTestCase
{
    public function testUnknownApiRouteReturnsJsonNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));

        $data = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('error', $data['status']);
    }
}
