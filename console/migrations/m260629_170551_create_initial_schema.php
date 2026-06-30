<?php

use yii\db\Migration;

/**
 * KeyForge core schema (§8 spec + ADR 0006).
 *
 * - pg_trgm extension (fuzzy-dedup, ADR 0002).
 * - All tables carry the kf_ prefix, including kf_project (ADR 0006).
 * - Tenant-scoped tables carry project_id INT NOT NULL DEFAULT 1 (FK -> kf_project);
 *   composite indexes LEAD with project_id (ADR 0004).
 * - kf_keyword: UNIQUE(project_id, import_hash) -> idempotent import (ADR 0006),
 *   plus a GIN pg_trgm index on normalized_keyword for fuzzy-dedup.
 * - kf_responsive_search_ad scopes transitively via ad_group_id (no own project_id, §8).
 */
final class m260629_170551_create_initial_schema extends Migration
{
    public function safeUp()
    {
        // pg_trgm powers fuzzy-dedup (similarity > 0.85, §2.5). IF NOT EXISTS keeps
        // re-runs on a shared cluster safe.
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // --- kf_project: tenant directory (multi-tenant port, isolation deferred §13) ---
        $this->createTable('kf_project', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull()->unique(),
            'created_at' => $this->timestamp()->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        // --- kf_import_batch: per-file import bookkeeping; file_hash feeds import_hash (ADR 0006) ---
        $this->createTable('kf_import_batch', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'file_name' => $this->string(500)->notNull(),
            'file_hash' => "char(64) NOT NULL",          // sha256 hex of file contents
            'rows_total' => $this->integer()->notNull()->defaultValue(0),
            'rows_imported' => $this->integer()->notNull()->defaultValue(0),
            'started_at' => $this->timestamp()->null(),
            'finished_at' => $this->timestamp()->null(),
        ]);

        // --- kf_keyword: canonical keyword model (§2.1) ---
        $this->createTable('kf_keyword', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'source_type' => $this->string(50)->notNull(),
            'raw_keyword' => $this->text()->notNull(),
            'normalized_keyword' => $this->text()->notNull(),
            'search_volume' => $this->integer()->null(),
            'detected_language' => $this->string(10)->null(),
            'source_country' => $this->string(10)->null(),
            'source_url' => $this->text()->null(),
            'import_hash' => "char(64) NOT NULL",        // sha256(source_type|file_hash|raw_keyword)
            'intent_class' => $this->string(20)->null(),
            'is_brand' => $this->boolean()->notNull()->defaultValue(false),
            'is_forbidden' => $this->boolean()->notNull()->defaultValue(false),
            'merged_into_keyword_id' => $this->integer()->null(),
            'is_opportunity' => $this->boolean()->notNull()->defaultValue(false),
            'status' => $this->string(20)->notNull()->defaultValue('new'),
        ]);

        // --- kf_negative_keyword: junk -> minus-words, NOT deleted (§2.2) ---
        $this->createTable('kf_negative_keyword', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'keyword_text' => $this->text()->notNull(),
            'reason' => $this->string(255)->null(),
            'language' => $this->string(10)->null(),
        ]);

        // --- kf_ad_group: STAG groups by (intent, language) (§2.8) ---
        $this->createTable('kf_ad_group', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'group_name' => $this->string(255)->notNull(),
            'intent_class' => $this->string(20)->null(),
            'language' => $this->string(10)->null(),
            'target_url' => $this->text()->null(),
        ]);

        // --- kf_responsive_search_ad: scopes transitively via ad_group_id (no project_id, §8) ---
        $this->createTable('kf_responsive_search_ad', [
            'id' => $this->primaryKey(),
            'ad_group_id' => $this->integer()->notNull(),
            'headlines' => 'jsonb NOT NULL',             // up to 15 headlines (<=30 chars)
            'descriptions' => 'jsonb NOT NULL',          // up to 4 descriptions (<=90 chars)
            'validation_status' => $this->string(20)->notNull()->defaultValue('pending'),
        ]);

        // --- kf_config_*: rules as DATA, not hardcode (§1) ---
        $this->createTable('kf_config_brand_term', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'term' => $this->string(255)->notNull(),
        ]);
        $this->createTable('kf_config_forbidden_term', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'term' => $this->string(255)->notNull(),
        ]);
        $this->createTable('kf_config_volume_threshold', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'language' => $this->string(10)->notNull(),
            'percentile' => $this->decimal(4, 3)->null(),       // adaptive per-language (§2.6)
            'min_search_volume' => $this->integer()->null(),    // absolute floor fallback
        ]);
        $this->createTable('kf_config_language_url_map', [
            'id' => $this->primaryKey(),
            'project_id' => $this->integer()->notNull()->defaultValue(1),
            'language' => $this->string(10)->notNull(),
            'target_url' => $this->text()->notNull(),
        ]);

        // --- Foreign keys (project_id -> kf_project; RESTRICT to guard tenant data) ---
        foreach ([
            'kf_import_batch', 'kf_keyword', 'kf_negative_keyword', 'kf_ad_group',
            'kf_config_brand_term', 'kf_config_forbidden_term',
            'kf_config_volume_threshold', 'kf_config_language_url_map',
        ] as $table) {
            $this->addForeignKey("fk_{$table}_project", $table, 'project_id', 'kf_project', 'id', 'RESTRICT', 'RESTRICT');
        }
        // RSA -> ad_group (deleting a group removes its ads).
        $this->addForeignKey('fk_kf_rsa_ad_group', 'kf_responsive_search_ad', 'ad_group_id', 'kf_ad_group', 'id', 'CASCADE', 'CASCADE');
        // Fuzzy-dedup canon pointer: merged rows point at the survivor (§2.5).
        $this->addForeignKey('fk_kf_keyword_merged_into', 'kf_keyword', 'merged_into_keyword_id', 'kf_keyword', 'id', 'SET NULL', 'CASCADE');

        // --- Indexes (composite indexes LEAD with project_id, ADR 0004) ---
        // Idempotent import (ADR 0006, §9): same file -> same hashes -> 0 new rows.
        $this->createIndex('uq_kf_keyword_project_import_hash', 'kf_keyword', ['project_id', 'import_hash'], true);
        // Filters/grouping by language, status, intent.
        $this->createIndex('idx_kf_keyword_project_language', 'kf_keyword', ['project_id', 'detected_language']);
        $this->createIndex('idx_kf_keyword_project_status', 'kf_keyword', ['project_id', 'status']);
        $this->createIndex('idx_kf_keyword_project_intent', 'kf_keyword', ['project_id', 'intent_class']);
        $this->createIndex('idx_kf_keyword_merged_into', 'kf_keyword', ['merged_into_keyword_id']);
        // GIN pg_trgm index for fuzzy-dedup similarity scans (§2.5).
        $this->execute('CREATE INDEX idx_kf_keyword_normalized_trgm ON kf_keyword USING gin (normalized_keyword gin_trgm_ops)');

        $this->createIndex('idx_kf_ad_group_project_intent_lang', 'kf_ad_group', ['project_id', 'intent_class', 'language']);
        $this->createIndex('idx_kf_rsa_ad_group', 'kf_responsive_search_ad', ['ad_group_id']);

        // One batch per (project, file content); re-import of same file is idempotent.
        $this->createIndex('uq_kf_import_batch_project_file', 'kf_import_batch', ['project_id', 'file_hash'], true);
        // No duplicate negatives (§11: "already-flagged negative not duplicated").
        $this->createIndex('uq_kf_negative_project_text', 'kf_negative_keyword', ['project_id', 'keyword_text'], true);
        // Config uniqueness per project.
        $this->createIndex('uq_kf_brand_project_term', 'kf_config_brand_term', ['project_id', 'term'], true);
        $this->createIndex('uq_kf_forbidden_project_term', 'kf_config_forbidden_term', ['project_id', 'term'], true);
        $this->createIndex('uq_kf_volume_project_language', 'kf_config_volume_threshold', ['project_id', 'language'], true);
        $this->createIndex('uq_kf_langurl_project_language', 'kf_config_language_url_map', ['project_id', 'language'], true);
    }

    public function safeDown()
    {
        // Drop in reverse dependency order (children before parents).
        $this->dropTable('kf_responsive_search_ad');
        $this->dropTable('kf_config_language_url_map');
        $this->dropTable('kf_config_volume_threshold');
        $this->dropTable('kf_config_forbidden_term');
        $this->dropTable('kf_config_brand_term');
        $this->dropTable('kf_ad_group');
        $this->dropTable('kf_negative_keyword');
        $this->dropTable('kf_keyword');           // self-FK + project FK dropped with table
        $this->dropTable('kf_import_batch');
        $this->dropTable('kf_project');
        // Leave pg_trgm — other schemas may rely on it; dropping is intentionally avoided.
    }
}
