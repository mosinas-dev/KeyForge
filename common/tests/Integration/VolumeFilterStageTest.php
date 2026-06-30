<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\VolumeFilterStage;
use common\repositories\PgConfigRepository;
use common\repositories\PgKeywordRepository;
use Yii;

/**
 * Phase 3: adaptive per-language volume cutoff (§2.6 / §11). Threshold = the
 * configured percentile WITHIN a language, so small languages aren't unfairly cut.
 * Edge cases: single-row language, all zeros, language with no threshold config.
 */
class VolumeFilterStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insert(string $language, ?int $volume): int
    {
        $hash = hash('sha256', 'vol-test|' . $language . '|' . $volume . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, search_volume, import_hash, status)
             VALUES (:p, 'test', :kw, :kw, :lang, :vol, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':kw' => $language . '-' . $this->counter, ':lang' => $language, ':vol' => $volume, ':hash' => $hash]
        )->queryScalar();
    }

    private function keywordStatus(int $id): string
    {
        return (string) Yii::$app->db->createCommand(
            'SELECT status FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryScalar();
    }

    private function runStage(): void
    {
        (new VolumeFilterStage(new PgKeywordRepository(Yii::$app->db), new PgConfigRepository(Yii::$app->db)))
            ->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testFiltersBelowPercentileWithinLanguage(): void
    {
        // en p25 over [100,1000,5000,49000] = 775 -> only 100 is below.
        $low = $this->insert('en', 100);
        $a = $this->insert('en', 1000);
        $b = $this->insert('en', 5000);
        $c = $this->insert('en', 49000);

        $this->runStage();

        $this->assertSame('low_volume', $this->keywordStatus($low));
        $this->assertSame('new', $this->keywordStatus($a));
        $this->assertSame('new', $this->keywordStatus($b));
        $this->assertSame('new', $this->keywordStatus($c));
    }

    public function testSingleKeywordLanguageIsNotFiltered(): void
    {
        $only = $this->insert('de', 500); // percentile of one value = itself, not below

        $this->runStage();

        $this->assertSame('new', $this->keywordStatus($only), 'the only keyword of a language must survive (§11)');
    }

    public function testAllZerosKeepsAll(): void
    {
        $x = $this->insert('es', 0);
        $y = $this->insert('es', 0);

        $this->runStage();

        $this->assertSame('new', $this->keywordStatus($x));
        $this->assertSame('new', $this->keywordStatus($y));
    }

    public function testLanguageWithoutThresholdConfigKeepsAll(): void
    {
        // 'fr' is not seeded in kf_config_volume_threshold -> no cutoff, don't crash.
        $x = $this->insert('fr', 1);
        $y = $this->insert('fr', 999999);

        $this->runStage();

        $this->assertSame('new', $this->keywordStatus($x));
        $this->assertSame('new', $this->keywordStatus($y));
    }
}
