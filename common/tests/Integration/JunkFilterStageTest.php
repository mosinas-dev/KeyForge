<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\JunkFilterStage;
use common\services\JunkClassifier;
use Yii;

/**
 * Phase 3: junk -> kf_negative_keyword, NOT deleted (§2.2 / §9). Idempotent.
 */
class JunkFilterStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insertKeyword(string $normalized): int
    {
        $hash = hash('sha256', 'junk-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, import_hash, status)
             VALUES (:p, 'test', :raw, :norm, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':raw' => $normalized, ':norm' => $normalized, ':hash' => $hash]
        )->queryScalar();
    }

    private function keywordStatus(int $id): string
    {
        return (string) Yii::$app->db->createCommand(
            'SELECT status FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryScalar();
    }

    private function negativeCount(string $text): int
    {
        return (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM kf_negative_keyword WHERE project_id = :p AND keyword_text = :t',
            [':p' => self::PROJECT_ID, ':t' => $text]
        )->queryScalar();
    }

    private function runStage(): PipelineContext
    {
        $stage = new JunkFilterStage(Yii::$app->db, new JunkClassifier());

        return $stage->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testMovesJunkToNegativesAndMarksStatus(): void
    {
        $special = $this->insertKeyword('????');
        $gibberish = $this->insertKeyword('asdkjh qwe');
        $clean = $this->insertKeyword('website builder');

        $this->runStage();

        $this->assertSame('junk', $this->keywordStatus($special));
        $this->assertSame('junk', $this->keywordStatus($gibberish));
        $this->assertSame('new', $this->keywordStatus($clean), 'clean keyword stays active');

        $this->assertSame(1, $this->negativeCount('????'));
        $this->assertSame(1, $this->negativeCount('asdkjh qwe'));
        $this->assertSame(0, $this->negativeCount('website builder'));

        $reason = Yii::$app->db->createCommand(
            'SELECT reason FROM kf_negative_keyword WHERE project_id = :p AND keyword_text = :t',
            [':p' => self::PROJECT_ID, ':t' => '????']
        )->queryScalar();
        $this->assertSame(JunkClassifier::REASON_SPECIAL_ONLY, $reason);
    }

    public function testIdempotentReRunDoesNotDuplicateNegatives(): void
    {
        $this->insertKeyword('????');
        $this->runStage();
        $context = $this->runStage();

        $this->assertSame(1, $this->negativeCount('????'), 're-run must not duplicate negatives (§11)');
        $this->assertSame(0, $context->stageStats()['junk_filter']['out'], 'no active rows left to process on re-run');
    }
}
