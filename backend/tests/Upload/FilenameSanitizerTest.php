<?php

declare(strict_types=1);

namespace App\Tests\Upload;

use App\Upload\FilenameSanitizer;
use App\Upload\UploadRejectedException;
use PHPUnit\Framework\TestCase;

final class FilenameSanitizerTest extends TestCase
{
    public function testRejectsDotDotSegments(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->sanitize('../../etc/passwd.csv');
    }

    public function testRejectsForwardSlash(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->sanitize('a/b.csv');
    }

    public function testRejectsBackslash(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->sanitize('a\\b.csv');
    }

    public function testRejectsNullByte(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->sanitize("evil.csv\0.php");
    }

    public function testRejectsEmptyFilename(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->sanitize('');
    }

    public function testStripsControlCharactersAndCollapsesWhitespace(): void
    {
        $result = (new FilenameSanitizer())->sanitize("my\x01data   file.csv");

        self::assertSame('mydata file.csv', $result);
    }

    public function testAcceptsOrdinaryFilename(): void
    {
        self::assertSame('sales-2026.csv', (new FilenameSanitizer())->sanitize('sales-2026.csv'));
    }

    public function testExtractExtensionAcceptsWhitelistedTypes(): void
    {
        $sanitizer = new FilenameSanitizer();

        self::assertSame('csv', $sanitizer->extractExtension('data.csv'));
        self::assertSame('xlsx', $sanitizer->extractExtension('data.xlsx'));
        self::assertSame('json', $sanitizer->extractExtension('data.json'));
    }

    public function testExtractExtensionRejectsDisallowedTypes(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->extractExtension('script.exe');
    }

    public function testExtractExtensionRejectsMacroEnabledWorkbook(): void
    {
        $this->expectException(UploadRejectedException::class);
        (new FilenameSanitizer())->extractExtension('workbook.xlsm');
    }
}
