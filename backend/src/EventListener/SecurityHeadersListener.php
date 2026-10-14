<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers = $response->headers;

        // Prevent MIME type sniffing
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking
        $headers->set('X-Frame-Options', 'DENY');

        // Strict referrer policy
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Prevent legacy Flash/Acrobat cross-domain leaks
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Isolate browsing context
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'cross-origin');

        // Disallow embedding for API responses
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
        }
    }
}
