<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\IntentClassifier;
use yii\db\Connection;

/**
 * Intent classification (§2.4): set intent_class on each active keyword. Only
 * commercial intent is later sent to Ads; informational is filtered at GAds-prep.
 */
final class IntentClassifyStage implements PipelineStage
{
    public function __construct(
        private Connection $db,
        private IntentClassifier $classifier,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->db->createCommand(
            'SELECT id, normalized_keyword FROM kf_keyword WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        foreach ($activeKeywords as $keyword) {
            $intent = $this->classifier->classify((string) $keyword['normalized_keyword']);
            $this->db->createCommand()
                ->update('kf_keyword', ['intent_class' => $intent], ['id' => $keyword['id']])
                ->execute();
        }

        $received = count($activeKeywords);
        $context->recordStage('intent_classify', $received, $received);

        return $context;
    }
}
