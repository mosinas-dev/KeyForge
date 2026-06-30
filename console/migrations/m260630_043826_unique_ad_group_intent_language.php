<?php

use yii\db\Migration;

/**
 * STAG groups are keyed by (project_id, intent_class, language) (§2.8): exactly one
 * ad group per intent+language per project. Replace the plain index with a UNIQUE
 * one so GadsPrepStage can upsert groups idempotently (ON CONFLICT) without
 * deleting+recreating (which would cascade-delete RSAs in Phase 5).
 */
final class m260630_043826_unique_ad_group_intent_language extends Migration
{
    public function safeUp()
    {
        $this->dropIndex('idx_kf_ad_group_project_intent_lang', 'kf_ad_group');
        $this->createIndex('uq_kf_ad_group_project_intent_lang', 'kf_ad_group', ['project_id', 'intent_class', 'language'], true);
    }

    public function safeDown()
    {
        $this->dropIndex('uq_kf_ad_group_project_intent_lang', 'kf_ad_group');
        $this->createIndex('idx_kf_ad_group_project_intent_lang', 'kf_ad_group', ['project_id', 'intent_class', 'language']);
    }
}
