<?php

declare(strict_types=1);

namespace App\Upload\Validator;

use App\Upload\ValidationResult;
use ZipArchive;

/**
 * Confirms an .xlsx upload is a structurally valid, safely-openable
 * OOXML workbook. Never extracts sheet data (that's phase 3+ profiling)
 * and never executes anything — macros in a legacy .xlsm are out of
 * scope entirely since .xlsm is not in the accepted extension whitelist.
 */
final class XlsxValidator implements FileValidatorInterface
{
    private const REQUIRED_ENTRIES = ['[Content_Types].xml', 'xl/workbook.xml'];
    private const MAX_ENTRIES = 10_000;
    private const MAX_TOTAL_UNCOMPRESSED_BYTES = 1_073_741_824; // 1 GiB
    private const MAX_COMPRESSION_RATIO = 200;

    public function validate(string $path): ValidationResult
    {
        $header = @file_get_contents($path, false, null, 0, 4);
        if ($header === false || !str_starts_with($header, "PK\x03\x04")) {
            return ValidationResult::invalid(['File is not a valid ZIP/XLSX archive.']);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($path, ZipArchive::RDONLY);
        if ($openResult !== true) {
            return ValidationResult::invalid(['Unable to open workbook archive.']);
        }

        try {
            return $this->validateArchive($zip);
        } finally {
            $zip->close();
        }
    }

    private function validateArchive(ZipArchive $zip): ValidationResult
    {
        $numEntries = $zip->numFiles;

        if ($numEntries === 0) {
            return ValidationResult::invalid(['Workbook archive is empty.']);
        }

        if ($numEntries > self::MAX_ENTRIES) {
            return ValidationResult::invalid(['Workbook archive has too many entries.']);
        }

        $foundEntries = [];
        $totalUncompressed = 0;

        for ($i = 0; $i < $numEntries; ++$i) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $foundEntries[$stat['name']] = true;
            $totalUncompressed += $stat['size'];

            if ($totalUncompressed > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                return ValidationResult::invalid(['Workbook exceeds the maximum allowed uncompressed size (possible decompression bomb).']);
            }

            if ($stat['comp_size'] > 0 && ($stat['size'] / $stat['comp_size']) > self::MAX_COMPRESSION_RATIO) {
                return ValidationResult::invalid(['Workbook entry has a suspicious compression ratio (possible decompression bomb).']);
            }
        }

        foreach (self::REQUIRED_ENTRIES as $required) {
            if (!isset($foundEntries[$required])) {
                return ValidationResult::invalid(['File is not a valid XLSX workbook (missing '.$required.').']);
            }
        }

        return ValidationResult::ok();
    }
}
