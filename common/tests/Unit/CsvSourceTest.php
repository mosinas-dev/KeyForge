<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\sources\CsvSource;

/**
 * Phase 2: CSV parsing edge cases (§11 Ingest). DB-less.
 * Covers empty/header-only, BOM, CP1251 vs UTF-8, ';' vs ',' delimiter,
 * quoted commas, truncated/extra columns, invalid/negative volume, fingerprint.
 */
class CsvSourceTest extends Unit
{
    private const MAP = [
        'raw_keyword' => 'keyword',
        'search_volume' => 'volume',
        'source_country' => 'country',
        'source_url' => 'url',
        'source_language' => null,
    ];

    /** @var string[] */
    private array $tempFiles = [];

    protected function _after(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function sourceFor(string $bytes, string $sourceType = 'test_src', array $map = self::MAP): CsvSource
    {
        $path = tempnam(sys_get_temp_dir(), 'kf_csv_');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return new CsvSource($path, $sourceType, $map);
    }

    /** @return array<int,array> */
    private function rowsOf(CsvSource $source): array
    {
        return iterator_to_array($source->rows(), false);
    }

    public function testParsesCommaDelimitedRows(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword,volume,country\nwebsite builder,49000,US\n"));
        $this->assertCount(1, $rows);
        $this->assertSame('website builder', $rows[0]['raw_keyword']);
        $this->assertSame(49000, $rows[0]['search_volume']);
        $this->assertSame('US', $rows[0]['source_country']);
        $this->assertNull($rows[0]['source_language']);
    }

    public function testEmptyFileYieldsNoRows(): void
    {
        $this->assertCount(0, $this->rowsOf($this->sourceFor('')));
    }

    public function testHeaderOnlyYieldsNoRows(): void
    {
        $this->assertCount(0, $this->rowsOf($this->sourceFor("keyword,volume,country\n")));
    }

    public function testSemicolonDelimiterIsDetected(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword;volume;country\nsite builder;100;DE\n"));
        $this->assertCount(1, $rows);
        $this->assertSame('site builder', $rows[0]['raw_keyword']);
        $this->assertSame(100, $rows[0]['search_volume']);
    }

    public function testQuotedValueWithCommaStaysOneField(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword,volume\n\"website builder, free\",100\n"));
        $this->assertSame('website builder, free', $rows[0]['raw_keyword']);
        $this->assertSame(100, $rows[0]['search_volume']);
    }

    public function testBomIsStripped(): void
    {
        $rows = $this->rowsOf($this->sourceFor("\u{FEFF}keyword,volume\nwebsite builder,100\n"));
        $this->assertCount(1, $rows);
        $this->assertSame('website builder', $rows[0]['raw_keyword'], 'BOM must not corrupt the first header');
    }

    public function testCp1251IsConvertedToUtf8(): void
    {
        $utf8 = "keyword,volume\nсайт конструктор,500\n";
        $cp1251 = mb_convert_encoding($utf8, 'Windows-1251', 'UTF-8');
        $rows = $this->rowsOf($this->sourceFor($cp1251));
        $this->assertSame('сайт конструктор', $rows[0]['raw_keyword']);
    }

    public function testTruncatedRowDoesNotCrashAndMissingFieldsAreNull(): void
    {
        // Second data row is missing volume + country.
        $rows = $this->rowsOf($this->sourceFor("keyword,volume,country\nfull,100,US\nonly keyword\n"));
        $this->assertCount(2, $rows);
        $this->assertSame('only keyword', $rows[1]['raw_keyword']);
        $this->assertNull($rows[1]['search_volume']);
        $this->assertNull($rows[1]['source_country']);
    }

    public function testExtraColumnsAreIgnored(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword,volume\nkw,100,EXTRA,MORE\n"));
        $this->assertSame('kw', $rows[0]['raw_keyword']);
        $this->assertSame(100, $rows[0]['search_volume']);
    }

    public function testNonNumericVolumeBecomesNull(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword,volume\nkw,n/a\n"));
        $this->assertNull($rows[0]['search_volume']);
    }

    public function testNegativeVolumeBecomesNull(): void
    {
        $rows = $this->rowsOf($this->sourceFor("keyword,volume\nkw,-5\n"));
        $this->assertNull($rows[0]['search_volume']);
    }

    public function testFingerprintIsSha256OfRawBytes(): void
    {
        $bytes = "keyword,volume\nkw,1\n";
        $source = $this->sourceFor($bytes);
        $this->assertSame(hash('sha256', $bytes), $source->fingerprint());
    }

    public function testSourceTypeIsReturned(): void
    {
        $this->assertSame('ahrefs_paid', $this->sourceFor('keyword\n', 'ahrefs_paid')->sourceType());
    }
}
