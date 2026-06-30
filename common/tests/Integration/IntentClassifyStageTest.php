<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\IntentClassifyStage;
use common\services\IntentClassifier;
use Yii;

/**
 * Phase 3: intent_class set on active keywords (§2.4).
 */
class IntentClassifyStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insertKeyword(string $normalized): int
    {
        $hash = hash('sha256', 'intent-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, import_hash, status)
             VALUES (:p, 'test', :raw, :norm, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':raw' => $normalized, ':norm' => $normalized, ':hash' => $hash]
        )->queryScalar();
    }

    private function intent(int $id): ?string
    {
        $value = Yii::$app->db->createCommand(
            'SELECT intent_class FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryScalar();

        return $value === false ? null : $value;
    }

    public function testSetsIntentClass(): void
    {
        $informational = $this->insertKeyword('what is a website builder');
        $commercial = $this->insertKeyword('free website builder');

        (new IntentClassifyStage(Yii::$app->db, new IntentClassifier()))->run(new PipelineContext(self::PROJECT_ID));

        $this->assertSame(IntentClassifier::INFORMATIONAL, $this->intent($informational));
        $this->assertSame(IntentClassifier::COMMERCIAL, $this->intent($commercial));
    }
}
