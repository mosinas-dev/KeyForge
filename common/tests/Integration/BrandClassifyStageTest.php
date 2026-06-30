<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\BrandClassifyStage;
use common\services\TermMatcher;
use Yii;

/**
 * Phase 3: brand flagging (§9). Uses the real seeded kf_config_brand_term
 * (site.pro / site pro / sitepro / ...). Idempotent.
 */
class BrandClassifyStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insertKeyword(string $normalized): int
    {
        $hash = hash('sha256', 'brand-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, import_hash, status)
             VALUES (:p, 'test', :raw, :norm, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':raw' => $normalized, ':norm' => $normalized, ':hash' => $hash]
        )->queryScalar();
    }

    private function isBrand(int $id): bool
    {
        return (bool) Yii::$app->db->createCommand(
            'SELECT is_brand FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryScalar();
    }

    private function runStage(): void
    {
        (new BrandClassifyStage(Yii::$app->db, new TermMatcher()))->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testFlagsBrandKeywordsOnly(): void
    {
        $brandA = $this->insertKeyword('site pro builder');
        $brandB = $this->insertKeyword('site.pro отзывы');
        $generic = $this->insertKeyword('website builder');
        $genericRu = $this->insertKeyword('конструктор сайтов');

        $this->runStage();

        $this->assertTrue($this->isBrand($brandA));
        $this->assertTrue($this->isBrand($brandB));
        $this->assertFalse($this->isBrand($generic), 'generic keyword must not be flagged brand');
        $this->assertFalse($this->isBrand($genericRu));
    }

    public function testIdempotentReRun(): void
    {
        $brand = $this->insertKeyword('site pro builder');
        $this->runStage();
        $this->runStage();
        $this->assertTrue($this->isBrand($brand));
    }
}
