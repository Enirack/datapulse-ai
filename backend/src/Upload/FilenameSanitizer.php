<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * Produces a safe, display-only filename from user input. The result is
 * NEVER used to build a filesystem path — storage identifiers are always
 * generated independently by the storage layer.
 */
final class FilenameSanitizer
{
    public const ALLOWED_EXTENSIONS = ['csv', 'xlsx', 'json'];

    /**
     * @throws UploadRejectedException if the filename is empty, contains a
     *                                 null byte, path separators, or a ".." segment
     */
    public function sanitize(string $rawFilename): string
    {
        if ($rawFilename === '' || str_contains($rawFilename, "\0")) {
            throw UploadRejectedException::invalidFilename();
        }

        if (str_contains($rawFilename, '/') || str_contains($rawFilename, '\\') || str_contains($rawFilename, '..')) {
            throw UploadRejectedException::invalidFilename();
        }

        // Strip ASCII control characters, collapse whitespace, cap length.
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $rawFilename) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if ($clean === '') {
            throw UploadRejectedException::invalidFilename();
        }

        return mb_substr($clean, 0, 255);
    }

    public function extractExtension(string $sanitizedFilename): string
    {
        $extension = strtolower(pathinfo($sanitizedFilename, PATHINFO_EXTENSION));

        if (!\in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw UploadRejectedException::unsupportedType(\sprintf(
                'Unsupported file extension "%s". Allowed: %s.',
                $extension !== '' ? $extension : '(none)',
                implode(', ', self::ALLOWED_EXTENSIONS),
            ));
        }

        return $extension;
    }
}
