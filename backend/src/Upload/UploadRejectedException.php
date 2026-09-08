<?php

declare(strict_types=1);

namespace App\Upload;

final class UploadRejectedException extends \RuntimeException
{
    private function __construct(string $message, public readonly int $httpStatusCode)
    {
        parent::__construct($message);
    }

    public static function missingFile(): self
    {
        return new self('No file was uploaded.', 400);
    }

    public static function invalidFilename(): self
    {
        return new self('Filename is invalid or unsafe.', 400);
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(\sprintf('File exceeds the maximum allowed size of %d bytes.', $maxBytes), 413);
    }

    public static function unsupportedType(string $reason): self
    {
        return new self($reason, 415);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalidContent(array $errors): self
    {
        return new self('File failed validation: '.implode(' ', $errors), 422);
    }

    public static function storageFailure(): self
    {
        return new self('Failed to store the uploaded file.', 500);
    }
}
