<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\services\KeywordPipelineService;
use common\sources\KeywordSourceFactory;
use Yii;

/**
 * Phase 2/7: JSON import works end-to-end (the ТЗ requires CSV *and* JSON), and the
 * cleaning pipeline applies to it just like CSV.
 */
class JsonImportTest extends Unit
{
    private const PROJECT_ID = 1;

    public function testImportsJsonSourceAndCleansIt(): void
    {
        $pipeline = Yii::createObject(KeywordPipelineService::class);
        $source = KeywordSourceFactory::fromFile(dirname(__DIR__, 3) . '/sample_data/ahrefs_organic_keywords.json');

        $context = $pipeline->importSource(self::PROJECT_ID, $source, 'ahrefs_organic_keywords.json');

        $this->assertGreaterThan(0, $context->stageStats()['ingest']['out'], 'JSON rows imported');

        // A JSON keyword landed in kf_keyword.
        $imported = (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND normalized_keyword = 'no code website builder'",
            [':p' => self::PROJECT_ID]
        )->queryScalar();
        $this->assertSame(1, $imported);

        // Junk from the JSON went to negatives (cleaning applied to JSON too).
        $junk = (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM kf_negative_keyword WHERE project_id = :p AND keyword_text = '????'",
            [':p' => self::PROJECT_ID]
        )->queryScalar();
        $this->assertSame(1, $junk);
    }
}
