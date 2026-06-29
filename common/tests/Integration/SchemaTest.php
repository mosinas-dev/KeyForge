<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use Yii;

/**
 * Phase 1 schema integration test (real PostgreSQL, keyforge_test).
 * Asserts the §8 schema invariants + decisions from ADR 0006:
 *  - pg_trgm extension present (fuzzy-dedup, ADR 0002);
 *  - every kf_* table exists;
 *  - UNIQUE(project_id, import_hash) on kf_keyword (idempotent import, ADR 0006);
 *  - tenant-scoped tables carry NOT NULL project_id;
 *  - kf_responsive_search_ad has NO own project_id (transitive via ad_group, §8).
 */
class SchemaTest extends Unit
{
    private const TENANT_SCOPED_TABLES = [
        'kf_keyword',
        'kf_negative_keyword',
        'kf_ad_group',
        'kf_import_batch',
        'kf_config_brand_term',
        'kf_config_forbidden_term',
        'kf_config_volume_threshold',
        'kf_config_language_url_map',
    ];

    private const ALL_TABLES = [
        'kf_project',
        'kf_keyword',
        'kf_negative_keyword',
        'kf_ad_group',
        'kf_responsive_search_ad',
        'kf_import_batch',
        'kf_config_brand_term',
        'kf_config_forbidden_term',
        'kf_config_volume_threshold',
        'kf_config_language_url_map',
    ];

    public function testPgTrgmExtensionInstalled(): void
    {
        $installed = Yii::$app->db
            ->createCommand("SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'")
            ->queryScalar();
        $this->assertEquals(1, $installed, 'pg_trgm extension must be installed (ADR 0002)');
    }

    public function testAllTablesExist(): void
    {
        foreach (self::ALL_TABLES as $table) {
            $this->assertNotNull(
                Yii::$app->db->schema->getTableSchema($table, true),
                "Table {$table} must exist (§8)"
            );
        }
    }

    public function testTenantScopedTablesHaveNotNullProjectId(): void
    {
        foreach (self::TENANT_SCOPED_TABLES as $table) {
            $column = Yii::$app->db->schema->getTableSchema($table, true)->getColumn('project_id');
            $this->assertNotNull($column, "{$table}.project_id must exist");
            $this->assertFalse($column->allowNull, "{$table}.project_id must be NOT NULL (ADR 0004)");
        }
    }

    public function testKeywordHasUniqueProjectIdImportHash(): void
    {
        $indexdef = Yii::$app->db->createCommand(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'kf_keyword' AND indexdef ILIKE '%UNIQUE%'"
        )->queryColumn();
        $matched = false;
        foreach ($indexdef as $def) {
            if (preg_match('/UNIQUE.*\(\s*project_id\s*,\s*import_hash\s*\)/i', $def)) {
                $matched = true;
            }
        }
        $this->assertTrue($matched, 'kf_keyword needs UNIQUE(project_id, import_hash) (ADR 0006, §9 idempotency)');
    }

    public function testResponsiveSearchAdHasNoOwnProjectId(): void
    {
        $rsa = Yii::$app->db->schema->getTableSchema('kf_responsive_search_ad', true);
        $this->assertNull(
            $rsa->getColumn('project_id'),
            'kf_responsive_search_ad scopes transitively via ad_group_id, no own project_id (§8)'
        );
    }
}
