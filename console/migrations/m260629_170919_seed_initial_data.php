<?php

use yii\db\Migration;

/**
 * Seed default project + config_* rules (plan fixed-decision #2, ADR 0006).
 *
 * These are STUBS editable in the admin later; real values from Борцов Гроуп
 * replace the seed (not a blocker). Rules live as DATA, not hardcode (§1).
 * Languages cover the sample data: ru/en/pt/es/de -> https://site.pro/<lang>.
 */
class m260629_170919_seed_initial_data extends Migration
{
    private const LANGUAGES = ['ru', 'en', 'pt', 'es', 'de'];

    public function safeUp()
    {
        // Default tenant (multi-tenant port; isolation deferred §13). Explicit id=1
        // matches the project_id DEFAULT 1; reset the serial so later inserts don't collide.
        $this->insert('kf_project', ['id' => 1, 'name' => 'Site.pro']);
        $this->execute("SELECT setval(pg_get_serial_sequence('kf_project', 'id'), (SELECT MAX(id) FROM kf_project))");

        // language -> target URL map.
        foreach (self::LANGUAGES as $language) {
            $this->insert('kf_config_language_url_map', [
                'project_id' => 1,
                'language' => $language,
                'target_url' => "https://site.pro/{$language}",
            ]);
        }

        // Brand terms (Site.pro variants; §9 requires these to be filterable).
        $brandTerms = ['site.pro', 'site pro', 'sitepro', 'site.pro builder', 'site.pro отзывы'];
        foreach ($brandTerms as $term) {
            $this->insert('kf_config_brand_term', ['project_id' => 1, 'term' => $term]);
        }

        // Forbidden terms — seed stubs (editable in admin; real list replaces later).
        foreach (['casino', 'porn'] as $term) {
            $this->insert('kf_config_forbidden_term', ['project_id' => 1, 'term' => $term]);
        }

        // Adaptive per-language volume threshold (§2.6): start at the 25th percentile,
        // no absolute floor. Tunable in admin.
        foreach (self::LANGUAGES as $language) {
            $this->insert('kf_config_volume_threshold', [
                'project_id' => 1,
                'language' => $language,
                'percentile' => 0.250,
                'min_search_volume' => null,
            ]);
        }
    }

    public function safeDown()
    {
        // Delete config first (FK -> kf_project), then the project.
        foreach ([
            'kf_config_volume_threshold', 'kf_config_forbidden_term',
            'kf_config_brand_term', 'kf_config_language_url_map',
        ] as $table) {
            $this->delete($table, ['project_id' => 1]);
        }
        $this->delete('kf_project', ['id' => 1]);
    }
}
