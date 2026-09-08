<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * Builds throwaway UploadedFile test doubles backed by real temporary
 * files (constructed with `test: true`, which is what lets Symfony's
 * UploadedFile skip the is_uploaded_file() check in a test context).
 */
final class FixtureFiles
{
    public static function csv(string $content, string $filename = 'data.csv'): UploadedFile
    {
        return self::fromContent($content, $filename, 'text/csv');
    }

    public static function json(string $content, string $filename = 'data.json'): UploadedFile
    {
        return self::fromContent($content, $filename, 'application/json');
    }

    /**
     * A genuinely valid, minimal .xlsx workbook (one empty sheet) — real
     * ZIP bytes with the OOXML parts a validator would actually check.
     */
    public static function xlsx(string $filename = 'data.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets>'
            .'</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>');

        $zip->close();

        return new UploadedFile($path, $filename, 'application/zip', test: true);
    }

    /**
     * A .xlsx-named file that is not a valid ZIP at all.
     */
    public static function corruptXlsx(string $filename = 'data.xlsx'): UploadedFile
    {
        return self::fromContent('this is not a zip file', $filename, 'application/zip');
    }

    /**
     * A genuinely valid ZIP archive, but not an XLSX workbook (missing the
     * required OOXML parts) — exercises the structural validator, not just
     * the extension/MIME guard.
     */
    public static function nonWorkbookZip(string $filename = 'data.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('hello.txt', 'not a workbook');
        $zip->close();

        return new UploadedFile($path, $filename, 'application/zip', test: true);
    }

    public static function fromContent(string $content, string $filename, string $mimeType): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mimeType, test: true);
    }
}
