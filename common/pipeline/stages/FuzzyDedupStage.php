<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\KeywordNormalizer;
use yii\db\Connection;

/**
 * Fuzzy dedup (§2.5). Clusters active keywords that are duplicates, keeping the
 * highest-volume one as canon and folding the rest (status='merged',
 * merged_into_keyword_id = canon).
 *
 * Two signals, union-find clustered, ALWAYS within the same detected_language
 * (different languages are never merged, §11):
 *  - dedupKey equality — word-order + diacritics ("builder website" / "grátis");
 *  - pg_trgm similarity >= 0.85 — typos that dedupKey misses ("ecomerce").
 *
 * Canon tie-break is deterministic: max search_volume, then lowest id.
 */
final class FuzzyDedupStage implements PipelineStage
{
    private const SIMILARITY_THRESHOLD = 0.85;

    private Connection $db;
    private KeywordNormalizer $normalizer;

    public function __construct(Connection $db, KeywordNormalizer $normalizer)
    {
        $this->db = $db;
        $this->normalizer = $normalizer;
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $keywords = $this->db->createCommand(
            'SELECT id, normalized_keyword, detected_language, search_volume FROM kf_keyword
             WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        $received = count($keywords);
        if ($received < 2) {
            $context->recordStage('fuzzy_dedup', $received, $received);

            return $context;
        }

        $parent = [];
        $volumeById = [];
        $keyGroups = [];
        foreach ($keywords as $keyword) {
            $id = (int) $keyword['id'];
            $parent[$id] = $id;
            $volumeById[$id] = $keyword['search_volume'] === null ? null : (int) $keyword['search_volume'];
            // language-scoped dedup key: same spelling in different languages stays distinct.
            $language = $keyword['detected_language'] ?? "\0";
            $key = $language . '|' . $this->normalizer->dedupKey((string) $keyword['normalized_keyword']);
            $keyGroups[$key][] = $id;
        }

        // 1) collapse exact dedupKey groups
        foreach ($keyGroups as $ids) {
            for ($i = 1, $n = count($ids); $i < $n; $i++) {
                $this->union($parent, $ids[0], $ids[$i]);
            }
        }

        // 2) collapse pg_trgm near-duplicates (same language)
        $pairs = $this->db->createCommand(
            'SELECT a.id AS a, b.id AS b
             FROM kf_keyword a
             JOIN kf_keyword b
               ON a.project_id = b.project_id
              AND a.detected_language IS NOT DISTINCT FROM b.detected_language
              AND a.id < b.id
             WHERE a.project_id = :p AND a.status = :s AND b.status = :s
               AND similarity(a.normalized_keyword, b.normalized_keyword) >= ' . self::SIMILARITY_THRESHOLD,
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();
        foreach ($pairs as $pair) {
            $this->union($parent, (int) $pair['a'], (int) $pair['b']);
        }

        // cluster, then fold each cluster into its canon
        $clusters = [];
        foreach (array_keys($parent) as $id) {
            $clusters[$this->find($parent, $id)][] = $id;
        }

        $merged = 0;
        foreach ($clusters as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $canon = $this->pickCanon($ids, $volumeById);
            foreach ($ids as $id) {
                if ($id === $canon) {
                    continue;
                }
                $this->db->createCommand()->update(
                    'kf_keyword',
                    ['status' => KeywordStatus::MERGED, 'merged_into_keyword_id' => $canon],
                    ['id' => $id]
                )->execute();
                // Re-point rows merged into a former canon (incremental passes) to the
                // new canon, so merged_into never chains through a merged row.
                $this->db->createCommand(
                    'UPDATE kf_keyword SET merged_into_keyword_id = :canon
                     WHERE project_id = :p AND merged_into_keyword_id = :former',
                    [':canon' => $canon, ':p' => $context->projectId, ':former' => $id]
                )->execute();
                $merged++;
            }
        }

        $context->recordStage('fuzzy_dedup', $received, $received - $merged);

        return $context;
    }

    /** Canon = highest search_volume (null treated as lowest), tie -> lowest id. */
    private function pickCanon(array $ids, array $volumeById): int
    {
        usort($ids, static function (int $x, int $y) use ($volumeById): int {
            $vx = $volumeById[$x] ?? -1;
            $vy = $volumeById[$y] ?? -1;

            return $vx !== $vy ? $vy <=> $vx : $x <=> $y;
        });

        return $ids[0];
    }

    /** @param array<int,int> $parent */
    private function find(array &$parent, int $x): int
    {
        while ($parent[$x] !== $x) {
            $parent[$x] = $parent[$parent[$x]]; // path halving
            $x = $parent[$x];
        }

        return $x;
    }

    /** @param array<int,int> $parent */
    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);
        if ($rootA !== $rootB) {
            $parent[max($rootA, $rootB)] = min($rootA, $rootB);
        }
    }
}
