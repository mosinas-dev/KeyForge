<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\KeywordRepositoryInterface;
use common\repositories\NegativeKeywordRepositoryInterface;
use common\services\JunkClassifier;

/**
 * Junk filter (§2.2): scans active keywords, and for each one the classifier flags
 * as junk sets status='junk' AND records it in kf_negative_keyword (NOT deleted —
 * the marketer gets minus-words for free). Idempotent: only active rows are
 * processed, and negatives are added ignoring duplicates (§11).
 */
final class JunkFilterStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private NegativeKeywordRepositoryInterface $negatives,
        private JunkClassifier $classifier,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->keywords->findActive($context->projectId);

        $movedToNegatives = 0;
        foreach ($activeKeywords as $keyword) {
            $reason = $this->classifier->classify($keyword['normalized_keyword']);
            if ($reason === null) {
                continue;
            }
            $this->keywords->markJunk($keyword['id']);
            $this->negatives->addIgnoringDuplicate(
                $context->projectId,
                $keyword['normalized_keyword'],
                $reason,
                $keyword['detected_language']
            );
            $movedToNegatives++;
        }

        $received = count($activeKeywords);
        $context->recordStage('junk_filter', $received, $received - $movedToNegatives);

        return $context;
    }
}
