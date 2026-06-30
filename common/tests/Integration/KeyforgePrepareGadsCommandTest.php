<?php

declare(strict_types=1);

namespace common\tests\Integration;

use console\controllers\KeyforgeController;
use Codeception\Test\Unit;
use yii\console\ExitCode;
use Yii;

/**
 * Phase 4: `yii keyforge/prepare-gads` runs GAds-prep over the project (§2.7–2.8).
 */
class KeyforgePrepareGadsCommandTest extends Unit
{
    private const PROJECT_ID = 1;

    private function insertEligibleKeyword(string $normalized, string $language): void
    {
        $hash = hash('sha256', 'prep-cmd|' . $normalized . '|' . $language);
        Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language,
                 search_volume, intent_class, import_hash, status)
             VALUES (:p, 'ahrefs_organic', :kw, :kw, :lang, 1000, 'commercial', :hash, 'new')",
            [':p' => self::PROJECT_ID, ':kw' => $normalized, ':lang' => $language, ':hash' => $hash]
        )->execute();
    }

    public function testPrepareGadsBuildsGroups(): void
    {
        $this->insertEligibleKeyword('website builder', 'en');

        $controller = Yii::createObject(KeyforgeController::class, ['keyforge', Yii::$app]);
        $exit = $controller->actionPrepareGads();
        $this->assertSame(ExitCode::OK, $exit);

        $group = Yii::$app->db->createCommand(
            "SELECT target_url FROM kf_ad_group WHERE project_id = :p AND intent_class = 'commercial' AND language = 'en'",
            [':p' => self::PROJECT_ID]
        )->queryScalar();
        $this->assertSame('https://site.pro/en', $group);
    }
}
