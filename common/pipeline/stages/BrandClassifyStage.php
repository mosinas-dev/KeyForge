<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\ConfigRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\services\TermMatcher;

/**
 * Brand classifier (§9): flags is_brand=true on active keywords matching a
 * configured brand term (kf_config_brand_term — rules as DATA). Flag only; the
 * actual exclusion from generated campaigns happens in GAds-prep (Phase 4).
 * Idempotent.
 */
final class BrandClassifyStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private ConfigRepositoryInterface $config,
        private TermMatcher $matcher,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $brandTerms = $this->config->brandTerms($context->projectId);
        $activeKeywords = $this->keywords->findActive($context->projectId);

        if ($brandTerms !== []) {
            foreach ($activeKeywords as $keyword) {
                if ($this->matcher->matchesAny($keyword['normalized_keyword'], $brandTerms)) {
                    $this->keywords->setBrand($keyword['id']);
                }
            }
        }

        // Annotation stage: the active funnel is unchanged (brand stays active until Phase 4).
        $received = count($activeKeywords);
        $context->recordStage('brand_classify', $received, $received);

        return $context;
    }
}
