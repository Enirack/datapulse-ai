<?php

declare(strict_types=1);

namespace App\Upload\Validator;

use App\Upload\ValidationResult;

/**
 * Confirms a CSV file is safely parseable and scans it for spreadsheet
 * formula injection risk (OWASP "CSV injection"). Does not profile the
 * data — that belongs to a later phase.
 */
final class CsvValidator implements FileValidatorInterface
{
    private const CANDIDATE_DELIMITERS = [',', ';', "\t", '|'];
    private const MAX_SCANNED_ROWS = 50_000;
    private const FORMULA_TRIGGER_CHARS = ['=', '+', '-', '@'];

    public function validate(string $path): ValidationResult
    {
        $sample = @file_get_contents($path, false, null, 0, 8192);
        if ($sample === false) {
            return ValidationResult::invalid(['Unable to read file.']);
        }

        if (str_contains($sample, "\0")) {
            return ValidationResult::invalid(['File does not look like text/CSV (contains binary data).']);
        }

        $delimiter = $this->detectDelimiter($sample);

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ValidationResult::invalid(['Unable to open file.']);
        }

        try {
            $rowCount = 0;
            $formulaInjectionRisk = false;

            while ($rowCount < self::MAX_SCANNED_ROWS && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                if ($row === [null] || $row === ['']) {
                    // blank line
                    continue;
                }

                ++$rowCount;

                if (!$formulaInjectionRisk) {
                    foreach ($row as $cell) {
                        if (\is_string($cell) && $this->looksLikeFormula($cell)) {
                            $formulaInjectionRisk = true;
                            break;
                        }
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rowCount === 0) {
            return ValidationResult::invalid(['CSV file has no parseable rows.']);
        }

        return ValidationResult::ok($formulaInjectionRisk);
    }

    private function detectDelimiter(string $sample): string
    {
        $lines = array_filter(explode("\n", $sample), static fn (string $line) => trim($line) !== '');
        $lines = \array_slice($lines, 0, 5);

        $best = ',';
        $bestScore = -1;

        foreach (self::CANDIDATE_DELIMITERS as $candidate) {
            $counts = [];
            foreach ($lines as $line) {
                $counts[] = \count(str_getcsv($line, $candidate, '"', ''));
            }

            if ($counts === []) {
                continue;
            }

            $min = min($counts);
            // Prefer delimiters that consistently produce more than one
            // column across the sampled lines.
            $score = $min > 1 ? $min : 0;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function looksLikeFormula(string $cell): bool
    {
        $trimmed = ltrim($cell);
        if ($trimmed === '' || !\in_array($trimmed[0], self::FORMULA_TRIGGER_CHARS, true)) {
            return false;
        }

        // A plain signed number (e.g. "-5", "+3.2") is not a formula.
        return !is_numeric($trimmed);
    }
}
