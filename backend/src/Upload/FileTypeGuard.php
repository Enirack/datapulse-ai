<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * Cross-checks the declared extension against the file's actual detected
 * MIME type, so the extension alone is never trusted. This is a coarse
 * first filter — the per-format FileValidatorInterface implementations do
 * the authoritative structural check.
 */
final class FileTypeGuard
{
    private const EMPTY_FILE_MIME_TYPES = ['inode/x-empty', 'application/x-empty'];

    private const MIME_EXPECTATIONS = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', ...self::EMPTY_FILE_MIME_TYPES],
        'json' => ['text/plain', 'application/json', 'text/x-json', ...self::EMPTY_FILE_MIME_TYPES],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream'],
    ];

    public function detectMimeType(string $path): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);

        return $detected !== false ? $detected : 'application/octet-stream';
    }

    /**
     * @throws UploadRejectedException if the detected MIME type is not
     *                                 plausible for the declared extension
     */
    public function assertPlausible(string $extension, string $detectedMimeType): void
    {
        $allowed = self::MIME_EXPECTATIONS[$extension] ?? [];

        if (!\in_array($detectedMimeType, $allowed, true)) {
            throw UploadRejectedException::unsupportedType(\sprintf(
                'File content does not match the declared type (.%s); detected "%s".',
                $extension,
                $detectedMimeType,
            ));
        }
    }
}
