<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\TermMatcher;
use yii\db\Connection;

/**
 * GAds-prep (§2.7–2.8). Operates on the cleaned, deduped keyword set:
 *  1. used — a keyword whose cluster has a google_ads source is already running
 *     in our Ads -> status='used' (excluded from new campaigns);
 *  2. forbidden — matches a kf_config_forbidden_term -> is_forbidden=true (excluded);
 *  3. competitor gap — an ahrefs_paid (competitor) keyword that is NOT used and not
 *     forbidden -> is_opportunity=true (used beats opportunity, §11);
 *  4. STAG groups — one kf_ad_group per (intent, language) over the eligible set
 *     (commercial, not brand/forbidden, language in the url map), monolingual,
 *     target_url from kf_config_language_url_map. Empty groups aren't created;
 *     a language with no url mapping is excluded (§11). Upsert -> idempotent.
 */
final class GadsPrepStage implements PipelineStage
{
    public function __construct(
        private Connection $db,
        private TermMatcher $matcher,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $projectId = $context->projectId;

        $this->markUsed($projectId);
        $this->markForbidden($projectId);
        $this->markOpportunity($projectId);
        [$eligibleKeywords, $groupCount] = $this->buildGroups($projectId);

        $context->recordStage('gads_prep', $eligibleKeywords, $groupCount);

        return $context;
    }

    /** A keyword already in google_ads (own row or a merged member) is "used". */
    private function markUsed(int $projectId): void
    {
        $this->db->createCommand(
            "UPDATE kf_keyword k SET status = :used
             WHERE k.project_id = :p AND k.status = :new
               AND (k.source_type = 'google_ads'
                    OR EXISTS (SELECT 1 FROM kf_keyword m
                               WHERE m.merged_into_keyword_id = k.id AND m.source_type = 'google_ads'))",
            [':used' => KeywordStatus::USED, ':p' => $projectId, ':new' => KeywordStatus::NEW]
        )->execute();
    }

    private function markForbidden(int $projectId): void
    {
        $terms = $this->db->createCommand(
            'SELECT term FROM kf_config_forbidden_term WHERE project_id = :p', [':p' => $projectId]
        )->queryColumn();
        if ($terms === []) {
            return;
        }

        $candidates = $this->db->createCommand(
            'SELECT id, normalized_keyword FROM kf_keyword
             WHERE project_id = :p AND status = :new AND is_forbidden = false',
            [':p' => $projectId, ':new' => KeywordStatus::NEW]
        )->queryAll();

        foreach ($candidates as $keyword) {
            if ($this->matcher->matchesAny((string) $keyword['normalized_keyword'], $terms)) {
                $this->db->createCommand()
                    ->update('kf_keyword', ['is_forbidden' => true], ['id' => $keyword['id']])
                    ->execute();
            }
        }
    }

    /** Competitor (ahrefs_paid) keyword that is still active (not used) and not forbidden. */
    private function markOpportunity(int $projectId): void
    {
        $this->db->createCommand(
            "UPDATE kf_keyword k SET is_opportunity = true
             WHERE k.project_id = :p AND k.status = :new AND k.is_forbidden = false
               AND (k.source_type = 'ahrefs_paid'
                    OR EXISTS (SELECT 1 FROM kf_keyword m
                               WHERE m.merged_into_keyword_id = k.id AND m.source_type = 'ahrefs_paid'))",
            [':p' => $projectId, ':new' => KeywordStatus::NEW]
        )->execute();
    }

    /**
     * Create/refresh one STAG group per (intent, language) over the eligible set.
     * @return array{0:int,1:int} [eligible keyword count, group count]
     */
    private function buildGroups(int $projectId): array
    {
        $eligibleFilter =
            "k.project_id = :p AND k.status = :new AND k.intent_class = 'commercial'
             AND k.is_brand = false AND k.is_forbidden = false";

        $eligibleKeywords = (int) $this->db->createCommand(
            "SELECT COUNT(*) FROM kf_keyword k
             JOIN kf_config_language_url_map m ON m.project_id = k.project_id AND m.language = k.detected_language
             WHERE {$eligibleFilter}",
            [':p' => $projectId, ':new' => KeywordStatus::NEW]
        )->queryScalar();

        $groups = $this->db->createCommand(
            "SELECT DISTINCT k.intent_class, k.detected_language AS language, m.target_url
             FROM kf_keyword k
             JOIN kf_config_language_url_map m ON m.project_id = k.project_id AND m.language = k.detected_language
             WHERE {$eligibleFilter}",
            [':p' => $projectId, ':new' => KeywordStatus::NEW]
        )->queryAll();

        foreach ($groups as $group) {
            $this->db->createCommand(
                'INSERT INTO kf_ad_group (project_id, group_name, intent_class, language, target_url)
                 VALUES (:p, :name, :intent, :lang, :url)
                 ON CONFLICT (project_id, intent_class, language)
                 DO UPDATE SET group_name = EXCLUDED.group_name, target_url = EXCLUDED.target_url',
                [
                    ':p' => $projectId,
                    ':name' => strtoupper($group['language']) . '_' . $group['intent_class'],
                    ':intent' => $group['intent_class'],
                    ':lang' => $group['language'],
                    ':url' => $group['target_url'],
                ]
            )->execute();
        }

        return [$eligibleKeywords, count($groups)];
    }
}
