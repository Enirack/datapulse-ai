<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersListenerTest extends WebTestCase
{
    public function testSecurityHeadersAreInjectedOnApiResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('none', $headers->get('X-Permitted-Cross-Domain-Policies'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        self::assertSame('cross-origin', $headers->get('Cross-Origin-Resource-Policy'));
        self::assertStringContainsString('frame-ancestors', (string) $headers->get('Content-Security-Policy'));
    }
}
