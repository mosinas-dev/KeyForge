<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\KeywordRepositoryInterface;
use common\services\LanguageDetector;

/**
 * Language detection (§2.3): detect each active keyword's language by its TEXT
 * (don't trust the source). A confident detection overwrites detected_language;
 * an undetermined one (short/translit/ambiguous) keeps the source-seeded value as
 * the fallback (§11). Validation against language->url is deferred to grouping
 * (Phase 4), where the map is actually used.
 */
final class LanguageDetectStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private LanguageDetector $detector,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->keywords->findActive($context->projectId);
        foreach ($activeKeywords as $keyword) {
            $detected = $this->detector->detect($keyword['normalized_keyword']);
            if ($detected === null) {
                continue; // keep source_language fallback
            }
            $this->keywords->setLanguage($keyword['id'], $detected);
        }

        $received = count($activeKeywords);
        $context->recordStage('language_detect', $received, $received);

        return $context;
    }
}
