<?php

declare(strict_types=1);

namespace common\repositories;

use common\pipeline\KeywordStatus;
use yii\db\Connection;

/**
 * PostgreSQL adapter for KeywordRepositoryInterface (§15): the single place
 * kf_keyword SQL lives. Uses PG-specific features freely (ON CONFLICT, RETURNING,
 * pg_trgm similarity, percentile_cont) per §15.11.
 */
final class PgKeywordRepository implements KeywordRepositoryInterface
{
    private const SOURCE_GOOGLE_ADS = 'google_ads';
    private const SOURCE_COMPETITOR = 'ahrefs_paid';
    private const INTENT_COMMERCIAL = 'commercial';

    public function __construct(private Connection $db)
    {
    }

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
    ): bool {
        $affected = $this->db->createCommand(
            'INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, search_volume,
                 detected_language, source_country, source_url, import_hash, status)
             VALUES (:project_id, :source_type, :raw, :norm, :vol, :lang, :country, :url, :hash, :status)
             ON CONFLICT (project_id, import_hash) DO NOTHING',
            [
                ':project_id' => $projectId,
                ':source_type' => $sourceType,
                ':raw' => $rawKeyword,
                ':norm' => $normalizedKeyword,
                ':vol' => $searchVolume,
                ':lang' => $detectedLanguage,
                ':country' => $sourceCountry,
                ':url' => $sourceUrl,
                ':hash' => $importHash,
                ':status' => KeywordStatus::NEW,
            ]
        )->execute();

        return $affected === 1;
    }

    public function findActive(int $projectId): array
    {
        $rows = $this->db->createCommand(
            'SELECT id, normalized_keyword, detected_language, search_volume FROM kf_keyword
             WHERE project_id = :p AND status = :s',
            [':p' => $projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'normalized_keyword' => (string) $row['normalized_keyword'],
            'detected_language' => $row['detected_language'],
            'search_volume' => $row['search_volume'] === null ? null : (int) $row['search_volume'],
        ], $rows);
    }

    public function findActiveNotForbidden(int $projectId): array
    {
        $rows = $this->db->createCommand(
            'SELECT id, normalized_keyword FROM kf_keyword
             WHERE project_id = :p AND status = :s AND is_forbidden = false',
            [':p' => $projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'normalized_keyword' => (string) $row['normalized_keyword'],
        ], $rows);
    }

    public function setBrand(int $keywordId): void
    {
        $this->update($keywordId, ['is_brand' => true]);
    }

    public function setForbidden(int $keywordId): void
    {
        $this->update($keywordId, ['is_forbidden' => true]);
    }

    public function setLanguage(int $keywordId, string $language): void
    {
        $this->update($keywordId, ['detected_language' => $language]);
    }

    public function setIntent(int $keywordId, string $intent): void
    {
        $this->update($keywordId, ['intent_class' => $intent]);
    }

    public function markJunk(int $keywordId): void
    {
        $this->update($keywordId, ['status' => KeywordStatus::JUNK]);
    }

    public function markMerged(int $keywordId, int $canonKeywordId): void
    {
        $this->update($keywordId, ['status' => KeywordStatus::MERGED, 'merged_into_keyword_id' => $canonKeywordId]);
    }

    public function repointMergedCanon(int $formerCanonId, int $newCanonId): void
    {
        $this->db->createCommand()
            ->update('kf_keyword', ['merged_into_keyword_id' => $newCanonId], ['merged_into_keyword_id' => $formerCanonId])
            ->execute();
    }

    public function findSimilarPairs(int $projectId, float $similarityThreshold): array
    {
        $rows = $this->db->createCommand(
            'SELECT a.id AS a, b.id AS b
             FROM kf_keyword a
             JOIN kf_keyword b
               ON a.project_id = b.project_id
              AND a.detected_language IS NOT DISTINCT FROM b.detected_language
              AND a.id < b.id
             WHERE a.project_id = :p AND a.status = :s AND b.status = :s
               AND similarity(a.normalized_keyword, b.normalized_keyword) >= ' . $similarityThreshold,
            [':p' => $projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        return array_map(static fn (array $row): array => ['a' => (int) $row['a'], 'b' => (int) $row['b']], $rows);
    }

    public function activeLanguages(int $projectId): array
    {
        return $this->db->createCommand(
            'SELECT DISTINCT detected_language FROM kf_keyword
             WHERE project_id = :p AND status = :s AND detected_language IS NOT NULL',
            [':p' => $projectId, ':s' => KeywordStatus::NEW]
        )->queryColumn();
    }

    public function percentileVolume(int $projectId, string $language, float $percentile): ?float
    {
        $value = $this->db->createCommand(
            'SELECT percentile_cont(:pct) WITHIN GROUP (ORDER BY search_volume)
             FROM kf_keyword
             WHERE project_id = :p AND status = :s AND detected_language = :lang AND search_volume IS NOT NULL',
            [':pct' => $percentile, ':p' => $projectId, ':s' => KeywordStatus::NEW, ':lang' => $language]
        )->queryScalar();

        return $value === null || $value === false ? null : (float) $value;
    }

    public function markLowVolumeBelow(int $projectId, string $language, float $threshold): int
    {
        return $this->db->createCommand(
            'UPDATE kf_keyword SET status = :low
             WHERE project_id = :p AND status = :new AND detected_language = :lang
               AND search_volume IS NOT NULL AND search_volume < :threshold',
            [
                ':low' => KeywordStatus::LOW_VOLUME,
                ':p' => $projectId,
                ':new' => KeywordStatus::NEW,
                ':lang' => $language,
                ':threshold' => $threshold,
            ]
        )->execute();
    }

    public function markUsedWithGoogleAds(int $projectId): void
    {
        $this->db->createCommand(
            'UPDATE kf_keyword k SET status = :used
             WHERE k.project_id = :p AND k.status = :new
               AND (k.source_type = :src
                    OR EXISTS (SELECT 1 FROM kf_keyword m
                               WHERE m.merged_into_keyword_id = k.id AND m.source_type = :src))',
            [':used' => KeywordStatus::USED, ':p' => $projectId, ':new' => KeywordStatus::NEW, ':src' => self::SOURCE_GOOGLE_ADS]
        )->execute();
    }

    public function markOpportunityFromCompetitor(int $projectId): void
    {
        $this->db->createCommand(
            'UPDATE kf_keyword k SET is_opportunity = true
             WHERE k.project_id = :p AND k.status = :new AND k.is_forbidden = false
               AND (k.source_type = :src
                    OR EXISTS (SELECT 1 FROM kf_keyword m
                               WHERE m.merged_into_keyword_id = k.id AND m.source_type = :src))',
            [':p' => $projectId, ':new' => KeywordStatus::NEW, ':src' => self::SOURCE_COMPETITOR]
        )->execute();
    }

    public function eligibleIntentLanguagePairs(int $projectId): array
    {
        $rows = $this->db->createCommand(
            'SELECT DISTINCT intent_class, detected_language FROM kf_keyword
             WHERE project_id = :p AND status = :s AND intent_class = :intent
               AND is_brand = false AND is_forbidden = false AND detected_language IS NOT NULL',
            [':p' => $projectId, ':s' => KeywordStatus::NEW, ':intent' => self::INTENT_COMMERCIAL]
        )->queryAll();

        return array_map(static fn (array $row): array => [
            'intent_class' => (string) $row['intent_class'],
            'detected_language' => (string) $row['detected_language'],
        ], $rows);
    }

    public function eligibleKeywords(int $projectId, string $intentClass, string $language): array
    {
        return $this->db->createCommand(
            'SELECT normalized_keyword FROM kf_keyword
             WHERE project_id = :p AND status = :s AND intent_class = :intent AND detected_language = :lang
               AND is_brand = false AND is_forbidden = false
             ORDER BY search_volume DESC NULLS LAST, id ASC',
            [':p' => $projectId, ':s' => KeywordStatus::NEW, ':intent' => $intentClass, ':lang' => $language]
        )->queryColumn();
    }

    /** @param array<string,mixed> $columns */
    private function update(int $keywordId, array $columns): void
    {
        $this->db->createCommand()->update('kf_keyword', $columns, ['id' => $keywordId])->execute();
    }
}
