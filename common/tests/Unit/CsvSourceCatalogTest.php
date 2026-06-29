<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\sources\CsvSourceCatalog;

/**
 * Phase 2: catalog maps the 4 real sample sources correctly. DB-less — parses the
 * actual files in sample_data/ to validate the column maps against real data.
 */
class CsvSourceCatalogTest extends Unit
{
    private function sampleDir(): string
    {
        return dirname(__DIR__, 3) . '/sample_data';
    }

    public function testInfersSourceTypeForEachSampleFile(): void
    {
        $expected = [
            'ahrefs_organic_keywords.csv' => 'ahrefs_organic',
            'ahrefs_paid_keywords.csv' => 'ahrefs_paid',
            'google_ads_keywords.csv' => 'google_ads',
            'search_console_queries.csv' => 'search_console',
        ];
        foreach ($expected as $file => $type) {
            $this->assertSame($type, CsvSourceCatalog::sourceTypeForFile($this->sampleDir() . '/' . $file));
        }
    }

    public function testParsesAhrefsOrganicSample(): void
    {
        $source = CsvSourceCatalog::fromFile($this->sampleDir() . '/ahrefs_organic_keywords.csv');
        $rows = iterator_to_array($source->rows(), false);
        $this->assertNotEmpty($rows);
        $this->assertSame('website builder', $rows[0]['raw_keyword']);
        $this->assertSame(49000, $rows[0]['search_volume']);
        $this->assertSame('US', $rows[0]['source_country']);
        $this->assertSame('https://site.pro/en', $rows[0]['source_url']);
    }

    public function testParsesGoogleAdsSampleWithLanguageAndMappedVolume(): void
    {
        $source = CsvSourceCatalog::fromFile($this->sampleDir() . '/google_ads_keywords.csv');
        $rows = iterator_to_array($source->rows(), false);
        $this->assertNotEmpty($rows);
        $this->assertSame('website builder', $rows[0]['raw_keyword']);
        $this->assertSame(49000, $rows[0]['search_volume'], 'google_ads volume comes from avg_monthly_searches');
        $this->assertSame('en', $rows[0]['source_language']);
    }

    public function testUnknownFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CsvSourceCatalog::sourceTypeForFile('/tmp/unknown_source.csv');
    }
}
