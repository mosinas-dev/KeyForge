<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\IngestStage;
use common\repositories\PgImportBatchRepository;
use common\repositories\PgKeywordRepository;
use common\services\ImportHashCalculator;
use common\services\KeywordNormalizer;
use common\sources\CsvSource;
use Yii;

/**
 * Phase 2 ingest integration (keyforge_test). §11/§9:
 *  - rows land in kf_keyword (raw + normalized + volume + source meta);
 *  - import_batch recorded;
 *  - re-import of the same file adds 0 (idempotent, UNIQUE(project_id, import_hash));
 *  - different files with the same keyword produce separate rows.
 */
class IngestStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private const MAP = [
        'raw_keyword' => 'keyword', 'search_volume' => 'volume',
        'source_country' => 'country', 'source_url' => null, 'source_language' => 'language',
    ];

    /** @var string[] */
    private array $tempFiles = [];

    protected function _after(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        $this->tempFiles = [];
    }

    private function ingest(string $bytes, string $sourceType): PipelineContext
    {
        $path = tempnam(sys_get_temp_dir(), 'kf_ing_');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        $stage = new IngestStage(
            new CsvSource($path, $sourceType, self::MAP),
            basename($path),
            new PgKeywordRepository(Yii::$app->db),
            new PgImportBatchRepository(Yii::$app->db),
            new ImportHashCalculator(),
            new KeywordNormalizer()
        );

        return $stage->run(new PipelineContext(self::PROJECT_ID));
    }

    private function keywordCount(string $sourceType): int
    {
        return (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND source_type = :s',
            [':p' => self::PROJECT_ID, ':s' => $sourceType]
        )->queryScalar();
    }

    public function testIngestsRowsWithNormalizationAndSourceMeta(): void
    {
        $this->ingest("keyword,volume,country,language\nWebsite Builder,49000,US,en\n", 'ahrefs_paid');

        $row = Yii::$app->db->createCommand(
            "SELECT * FROM kf_keyword WHERE project_id = :p AND source_type = 'ahrefs_paid'",
            [':p' => self::PROJECT_ID]
        )->queryOne();

        $this->assertSame('Website Builder', $row['raw_keyword']);
        $this->assertSame('website builder', $row['normalized_keyword']);
        $this->assertSame(49000, (int) $row['search_volume']);
        $this->assertSame('US', $row['source_country']);
        $this->assertSame('en', $row['detected_language'], 'source language seeds detected_language (Phase 3 fallback)');
        $this->assertSame('new', $row['status']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['import_hash']);
    }

    public function testRecordsImportBatch(): void
    {
        $context = $this->ingest("keyword,volume\nkw one,10\nkw two,20\n", 'google_ads');
        $this->assertNotNull($context->importBatchId);

        $batch = Yii::$app->db->createCommand(
            'SELECT * FROM kf_import_batch WHERE id = :id', [':id' => $context->importBatchId]
        )->queryOne();
        $this->assertSame(2, (int) $batch['rows_total']);
        $this->assertSame(2, (int) $batch['rows_imported']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $batch['file_hash']);
    }

    public function testReimportSameFileAddsZeroNewRows(): void
    {
        $bytes = "keyword,volume\nwebsite builder,49000\nfree website builder,27000\n";
        $this->ingest($bytes, 'ahrefs_organic');
        $this->assertSame(2, $this->keywordCount('ahrefs_organic'));

        $context = $this->ingest($bytes, 'ahrefs_organic');
        $this->assertSame(2, $this->keywordCount('ahrefs_organic'), 're-import of same file = 0 new (§9)');
        $this->assertSame(0, $context->stageStats()['ingest']['out'], 'rows_imported must be 0 on re-import');
    }

    public function testDifferentFilesSameKeywordCreateSeparateRows(): void
    {
        $this->ingest("keyword,volume\nsite builder,100\n", 'search_console');
        $this->ingest("keyword,volume\nsite builder,999\n", 'search_console'); // different file contents
        $this->assertSame(2, $this->keywordCount('search_console'), 'different files, same keyword -> 2 rows (§11)');
    }
}
