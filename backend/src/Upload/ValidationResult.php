<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * Outcome of validating an uploaded file's content against its declared
 * format. `errors` means the file is rejected; `formulaInjectionRisk` is
 * purely informational and never blocks ingestion.
 */
final class ValidationResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly bool $formulaInjectionRisk,
    ) {
    }

    public static function ok(bool $formulaInjectionRisk = false): self
    {
        return new self(true, [], $formulaInjectionRisk);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors, false);
    }
}
