<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\TermMatcher;
use yii\db\Connection;

/**
 * Brand classifier (§9): flags is_brand=true on active keywords matching a
 * configured brand term (kf_config_brand_term — rules as DATA). Flag only; the
 * actual exclusion from generated campaigns happens in GAds-prep (Phase 4).
 * Idempotent.
 */
final class BrandClassifyStage implements PipelineStage
{
    private Connection $db;
    private TermMatcher $matcher;

    public function __construct(Connection $db, TermMatcher $matcher)
    {
        $this->db = $db;
        $this->matcher = $matcher;
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $brandTerms = $this->db->createCommand(
            'SELECT term FROM kf_config_brand_term WHERE project_id = :p',
            [':p' => $context->projectId]
        )->queryColumn();

        $activeKeywords = $this->db->createCommand(
            'SELECT id, normalized_keyword FROM kf_keyword WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        if ($brandTerms !== []) {
            foreach ($activeKeywords as $keyword) {
                if (!$this->matcher->matchesAny((string) $keyword['normalized_keyword'], $brandTerms)) {
                    continue;
                }
                $this->db->createCommand()
                    ->update('kf_keyword', ['is_brand' => true], ['id' => $keyword['id']])
                    ->execute();
            }
        }

        // Annotation stage: the active funnel is unchanged (brand stays active until Phase 4).
        $received = count($activeKeywords);
        $context->recordStage('brand_classify', $received, $received);

        return $context;
    }
}
