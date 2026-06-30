<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use console\controllers\KeyforgeController;
use yii\console\ExitCode;
use Yii;

/**
 * Phase 6: `yii keyforge/export` writes the Google Ads Editor files to disk (§2.10).
 */
class KeyforgeExportCommandTest extends Unit
{
    private const PROJECT_ID = 1;
    private string $outputDir = '';

    protected function _before(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/kf_export_' . bin2hex(random_bytes(4));
    }

    protected function _after(): void
    {
        foreach (glob($this->outputDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outputDir);
    }

    public function testExportWritesCampaignAndNegativeFiles(): void
    {
        $group = (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_ad_group (project_id, group_name, intent_class, language, target_url)
             VALUES (:p, 'EN_commercial', 'commercial', 'en', 'https://site.pro/en') RETURNING id",
            [':p' => self::PROJECT_ID]
        )->queryScalar();
        Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword (project_id, source_type, raw_keyword, normalized_keyword, detected_language,
                intent_class, import_hash, status)
             VALUES (:p, 'ahrefs_organic', 'website builder', 'website builder', 'en', 'commercial', :h, 'new')",
            [':p' => self::PROJECT_ID, ':h' => hash('sha256', 'export-cmd|kw')]
        )->execute();
        Yii::$app->db->createCommand(
            "INSERT INTO kf_responsive_search_ad (ad_group_id, headlines, descriptions, validation_status)
             VALUES (:g, CAST(:h AS jsonb), CAST(:d AS jsonb), 'valid')",
            [
                ':g' => $group,
                ':h' => json_encode([['text' => 'Site.pro', 'pin' => 1], ['text' => 'H2', 'pin' => null], ['text' => 'H3', 'pin' => null]]),
                ':d' => json_encode([['text' => 'Desc one', 'pin' => null], ['text' => 'Desc two', 'pin' => null]]),
            ]
        )->execute();

        /** @var KeyforgeController $controller */
        $controller = Yii::createObject(KeyforgeController::class, ['keyforge', Yii::$app]);
        $controller->outputDir = $this->outputDir;

        $exit = $controller->actionExport();

        $this->assertSame(ExitCode::OK, $exit);
        $this->assertFileExists($this->outputDir . '/campaigns.csv');
        $this->assertFileExists($this->outputDir . '/negatives.csv');
        $this->assertStringContainsString('website builder', (string) file_get_contents($this->outputDir . '/campaigns.csv'));
    }
}
