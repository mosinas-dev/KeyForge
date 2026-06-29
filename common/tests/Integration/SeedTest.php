<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use Yii;

/**
 * Phase 1 seed integration test (keyforge_test).
 * Asserts the seed from the plan's fixed decision #2 + ADR 0006:
 *  - kf_project(id=1, 'Site.pro');
 *  - language->url map ru/en/pt/es/de -> https://site.pro/<lang>;
 *  - brand terms include the §9 examples;
 *  - per-language volume thresholds; at least one forbidden stub.
 */
class SeedTest extends Unit
{
    public function testDefaultProjectSeeded(): void
    {
        $name = Yii::$app->db->createCommand("SELECT name FROM kf_project WHERE id = 1")->queryScalar();
        $this->assertSame('Site.pro', $name, 'kf_project(1, Site.pro) must be seeded (ADR 0004/0006)');
    }

    public function testLanguageUrlMapSeeded(): void
    {
        $rows = Yii::$app->db->createCommand(
            "SELECT language, target_url FROM kf_config_language_url_map WHERE project_id = 1"
        )->queryAll();
        $map = array_column($rows, 'target_url', 'language');
        foreach (['ru', 'en', 'pt', 'es', 'de'] as $lang) {
            $this->assertArrayHasKey($lang, $map, "language->url map must include {$lang}");
            $this->assertSame("https://site.pro/{$lang}", $map[$lang], "{$lang} -> https://site.pro/{$lang}");
        }
    }

    public function testBrandTermsSeeded(): void
    {
        $terms = Yii::$app->db->createCommand(
            "SELECT lower(term) FROM kf_config_brand_term WHERE project_id = 1"
        )->queryColumn();
        $this->assertNotEmpty($terms, 'brand terms must be seeded');
        // §9 calls out these brand examples must be filterable.
        $this->assertContains('site.pro', $terms);
        $this->assertContains('site pro', $terms);
    }

    public function testVolumeThresholdsSeededPerLanguage(): void
    {
        $languages = Yii::$app->db->createCommand(
            "SELECT language FROM kf_config_volume_threshold WHERE project_id = 1"
        )->queryColumn();
        foreach (['ru', 'en', 'pt', 'es', 'de'] as $lang) {
            $this->assertContains($lang, $languages, "volume threshold must exist for {$lang}");
        }
    }

    public function testForbiddenStubSeeded(): void
    {
        $count = (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM kf_config_forbidden_term WHERE project_id = 1"
        )->queryScalar();
        $this->assertGreaterThan(0, $count, 'at least one forbidden stub must be seeded (editable in admin)');
    }
}
