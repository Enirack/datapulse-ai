<?php

declare(strict_types=1);

namespace App\Profiling;

/**
 * Always carries an already-safe-to-display message: either the data
 * engine's own sanitized JSON error message, or one of the fixed strings
 * below for communication failures. Never a raw transport exception
 * message, which could contain a URL or other internal detail.
 */
final class DataEngineException extends \RuntimeException
{
    private function __construct(string $message, public readonly int $httpStatusCode)
    {
        parent::__construct($message);
    }

    public static function fromResponse(int $statusCode, string $message): self
    {
        return new self($message, $statusCode);
    }

    public static function communicationFailure(): self
    {
        return new self('Unable to reach the data engine.', 502);
    }
}
