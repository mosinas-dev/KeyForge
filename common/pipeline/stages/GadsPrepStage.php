<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\AdGroupRepositoryInterface;
use common\repositories\ConfigRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\services\TermMatcher;

/**
 * GAds-prep (§2.7–2.8) on the cleaned, deduped keyword set:
 *  1. used — a keyword whose cluster has a google_ads source -> status='used';
 *  2. forbidden — matches a forbidden term -> is_forbidden=true;
 *  3. competitor gap — an ahrefs_paid keyword not used and not forbidden ->
 *     is_opportunity (used beats opportunity, §11);
 *  4. STAG groups — one ad group per eligible (intent, language) whose language is
 *     in the url map; monolingual, target_url from the map; empty/unmapped skipped;
 *     upsert -> idempotent.
 */
final class GadsPrepStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private ConfigRepositoryInterface $config,
        private AdGroupRepositoryInterface $adGroups,
        private TermMatcher $matcher,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $projectId = $context->projectId;

        $this->keywords->markUsedWithGoogleAds($projectId);
        $this->markForbidden($projectId);
        $this->keywords->markOpportunityFromCompetitor($projectId);
        [$eligibleKeywords, $groupCount] = $this->buildGroups($projectId);

        $context->recordStage('gads_prep', $eligibleKeywords, $groupCount);

        return $context;
    }

    private function markForbidden(int $projectId): void
    {
        $terms = $this->config->forbiddenTerms($projectId);
        if ($terms === []) {
            return;
        }
        foreach ($this->keywords->findActiveNotForbidden($projectId) as $keyword) {
            if ($this->matcher->matchesAny($keyword['normalized_keyword'], $terms)) {
                $this->keywords->setForbidden($keyword['id']);
            }
        }
    }

    /**
     * Create/refresh one STAG group per eligible (intent, language) that has a URL.
     * @return array{0:int,1:int} [eligible keyword count, group count]
     */
    private function buildGroups(int $projectId): array
    {
        $eligibleKeywords = 0;
        $groupCount = 0;
        foreach ($this->keywords->eligibleIntentLanguagePairs($projectId) as $pair) {
            $language = $pair['detected_language'];
            $intent = $pair['intent_class'];
            $targetUrl = $this->config->languageUrl($projectId, $language);
            if ($targetUrl === null) {
                continue; // language without a target_url is excluded (§11)
            }
            $this->adGroups->upsertGroup($projectId, strtoupper($language) . '_' . $intent, $intent, $language, $targetUrl);
            $groupCount++;
            $eligibleKeywords += count($this->keywords->eligibleKeywords($projectId, $intent, $language));
        }

        return [$eligibleKeywords, $groupCount];
    }
}
