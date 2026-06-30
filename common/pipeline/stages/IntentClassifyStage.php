<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\KeywordRepositoryInterface;
use common\services\IntentClassifier;

/**
 * Intent classification (§2.4): set intent_class on each active keyword. Only
 * commercial intent is later sent to Ads; informational is filtered at GAds-prep.
 */
final class IntentClassifyStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private IntentClassifier $classifier,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->keywords->findActive($context->projectId);
        foreach ($activeKeywords as $keyword) {
            $this->keywords->setIntent($keyword['id'], $this->classifier->classify($keyword['normalized_keyword']));
        }

        $received = count($activeKeywords);
        $context->recordStage('intent_classify', $received, $received);

        return $context;
    }
}
