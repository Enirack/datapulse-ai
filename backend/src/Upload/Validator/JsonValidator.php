<?php

declare(strict_types=1);

namespace App\Upload\Validator;

use App\Upload\ValidationResult;

/**
 * Confirms an uploaded file is syntactically valid JSON. The overall
 * request size is already bounded by MAX_UPLOAD_SIZE before this runs.
 *
 * Future tabular conversion (phase 3+) will expect the common shape of
 * either a top-level array of flat objects, or an object with a top-level
 * array property — this validator intentionally does not enforce or
 * convert that shape yet, only confirms the JSON itself parses.
 */
final class JsonValidator implements FileValidatorInterface
{
    private const MAX_DEPTH = 512;

    public function validate(string $path): ValidationResult
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return ValidationResult::invalid(['Unable to read file.']);
        }

        if (trim($contents) === '') {
            return ValidationResult::invalid(['JSON file is empty.']);
        }

        json_decode($contents, associative: true, depth: self::MAX_DEPTH, flags: JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ValidationResult::invalid(['Malformed JSON: '.json_last_error_msg()]);
        }

        return ValidationResult::ok();
    }
}
