<?php

declare(strict_types=1);

namespace common\repositories;

/**
 * Repository for the kf_keyword aggregate (§15.8/15.12/15.16). All keyword SQL lives
 * behind this port; stages/services depend on it, never on Connection or Yii::$app.
 * Rows are returned as typed associative arrays (targeted refactor — no value objects/
 * collections yet, §14 deferred bits).
 */
interface KeywordRepositoryInterface
{
    /** Insert a keyword unless its (project_id, import_hash) already exists. Returns true if inserted. */
    public function insertIfNew(
        int $projectId,
        string $sourceType,
        string $rawKeyword,
        string $normalizedKeyword,
        ?int $searchVolume,
        ?string $detectedLanguage,
        ?string $sourceCountry,
        ?string $sourceUrl,
        string $importHash,
    ): bool;

    /** @return array<int,array{id:int,normalized_keyword:string,detected_language:?string,search_volume:?int}> active (status='new') */
    public function findActive(int $projectId): array;

    /** @return array<int,array{id:int,normalized_keyword:string}> active and not yet flagged forbidden */
    public function findActiveNotForbidden(int $projectId): array;

    public function setBrand(int $keywordId): void;

    public function setForbidden(int $keywordId): void;

    public function setLanguage(int $keywordId, string $language): void;

    public function setIntent(int $keywordId, string $intent): void;

    public function markJunk(int $keywordId): void;

    public function markMerged(int $keywordId, int $canonKeywordId): void;

    /** Re-point keywords merged into a former canon onto the new canon (incremental dedup). */
    public function repointMergedCanon(int $formerCanonId, int $newCanonId): void;

    /** @return array<int,array{a:int,b:int}> id pairs of active same-language keywords with trgm similarity >= threshold */
    public function findSimilarPairs(int $projectId, float $similarityThreshold): array;

    /** @return string[] distinct non-null detected_language of active keywords */
    public function activeLanguages(int $projectId): array;

    /** Continuous percentile of active keywords' search_volume within a language, or null if none. */
    public function percentileVolume(int $projectId, string $language, float $percentile): ?float;

    /** Mark active keywords of a language below threshold as low_volume; returns count moved. */
    public function markLowVolumeBelow(int $projectId, string $language, float $threshold): int;

    /** A keyword whose cluster contains a google_ads source -> status='used'. */
    public function markUsedWithGoogleAds(int $projectId): void;

    /** An active, non-forbidden ahrefs_paid (competitor) keyword -> is_opportunity. */
    public function markOpportunityFromCompetitor(int $projectId): void;

    /** @return array<int,array{intent_class:string,detected_language:string}> distinct eligible (commercial, not brand/forbidden) pairs */
    public function eligibleIntentLanguagePairs(int $projectId): array;

    /** @return string[] eligible keyword texts of a group, highest volume first */
    public function eligibleKeywords(int $projectId, string $intentClass, string $language): array;
}
