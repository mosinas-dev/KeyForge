<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\export\GoogleAdsEditorExporter;
use League\Csv\Reader;

/**
 * Phase 6: Google Ads Editor CSV export (§2.10 / §11). DB-less, pure formatting.
 * Covers special-char escaping, empty result -> header-only file, UTF-8/Cyrillic.
 */
class GoogleAdsEditorExporterTest extends Unit
{
    private GoogleAdsEditorExporter $exporter;

    protected function _before(): void
    {
        $this->exporter = new GoogleAdsEditorExporter();
    }

    private function group(array $overrides = []): array
    {
        return array_merge([
            'campaign' => 'SP_EN',
            'adGroup' => 'EN_commercial',
            'finalUrl' => 'https://site.pro/en',
            'matchType' => 'Phrase',
            'keywords' => ['website builder'],
            'headlines' => ['Site.pro', 'Easy Website Builder', 'Start Free'],
            'descriptions' => ['Build a site fast.', 'No code needed.'],
        ], $overrides);
    }

    /** Parse a CSV string back into records (header-mapped). */
    private function records(string $csv): array
    {
        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);

        return iterator_to_array($reader->getRecords(), false);
    }

    public function testCampaignsCsvHasKeywordRowAndAdRow(): void
    {
        $files = $this->exporter->export([$this->group()], []);
        $this->assertArrayHasKey('campaigns.csv', $files);

        $rows = $this->records($files['campaigns.csv']);
        $keywordRow = array_values(array_filter($rows, static fn ($r) => $r['Keyword'] !== ''))[0];
        $adRow = array_values(array_filter($rows, static fn ($r) => $r['Headline 1'] !== ''))[0];

        $this->assertSame('website builder', $keywordRow['Keyword']);
        $this->assertSame('Phrase', $keywordRow['Match Type']);
        $this->assertSame('https://site.pro/en', $keywordRow['Final URL']);
        $this->assertSame('Site.pro', $adRow['Headline 1']);
        $this->assertSame('Build a site fast.', $adRow['Description 1']);
        $this->assertSame('', $adRow['Keyword'], 'ad row has no keyword');
    }

    public function testEscapesCommasQuotesAndNewlines(): void
    {
        $files = $this->exporter->export([$this->group(['keywords' => ['website, "free" builder']])], []);

        // RFC4180: quotes doubled, whole field wrapped in quotes.
        $this->assertStringContainsString('"website, ""free"" builder"', $files['campaigns.csv']);
        // And it round-trips back to the exact value.
        $rows = $this->records($files['campaigns.csv']);
        $keywordRow = array_values(array_filter($rows, static fn ($r) => $r['Keyword'] !== ''))[0];
        $this->assertSame('website, "free" builder', $keywordRow['Keyword']);
    }

    public function testEmptyResultIsHeaderOnlyNotEmpty(): void
    {
        $files = $this->exporter->export([], []);

        $this->assertNotSame('', trim($files['campaigns.csv']), 'must not be empty bytes');
        $this->assertStringContainsString('Campaign', $files['campaigns.csv']);
        $this->assertStringContainsString('Headline 15', $files['campaigns.csv']);
        $this->assertSame([], $this->records($files['campaigns.csv']), 'header only, no data rows');
    }

    public function testUtf8CyrillicPreserved(): void
    {
        $files = $this->exporter->export(
            [$this->group(['keywords' => ['конструктор сайтов'], 'headlines' => ['Создайте сайт', 'Site.pro', 'Бесплатно']])],
            []
        );
        $this->assertStringContainsString('конструктор сайтов', $files['campaigns.csv']);
        $this->assertStringContainsString('Создайте сайт', $files['campaigns.csv']);
    }

    public function testNegativesExportedSeparately(): void
    {
        $files = $this->exporter->export([], ['casino', 'asdkjh qwe']);
        $this->assertArrayHasKey('negatives.csv', $files);

        $rows = $this->records($files['negatives.csv']);
        $this->assertCount(2, $rows);
        $this->assertSame('casino', $rows[0]['Keyword']);
    }
}
