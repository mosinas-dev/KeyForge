<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\KeywordRepositoryInterface;
use common\services\KeywordNormalizer;

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

    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private KeywordNormalizer $normalizer,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $keywords = $this->keywords->findActive($context->projectId);

        $received = count($keywords);
        if ($received < 2) {
            $context->recordStage('fuzzy_dedup', $received, $received);

            return $context;
        }

        $parent = [];
        $volumeById = [];
        $keyGroups = [];
        foreach ($keywords as $keyword) {
            $id = $keyword['id'];
            $parent[$id] = $id;
            $volumeById[$id] = $keyword['search_volume'];
            // language-scoped dedup key: same spelling in different languages stays distinct.
            $language = $keyword['detected_language'] ?? "\0";
            $key = $language . '|' . $this->normalizer->dedupKey($keyword['normalized_keyword']);
            $keyGroups[$key][] = $id;
        }

        // 1) collapse exact dedupKey groups
        foreach ($keyGroups as $ids) {
            for ($i = 1, $n = count($ids); $i < $n; $i++) {
                $this->union($parent, $ids[0], $ids[$i]);
            }
        }

        // 2) collapse pg_trgm near-duplicates (same language)
        foreach ($this->keywords->findSimilarPairs($context->projectId, self::SIMILARITY_THRESHOLD) as $pair) {
            $this->union($parent, $pair['a'], $pair['b']);
        }

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
                $this->keywords->markMerged($id, $canon);
                // Re-point rows merged into a former canon (incremental passes) to the
                // new canon, so merged_into never chains through a merged row.
                $this->keywords->repointMergedCanon($id, $canon);
                $merged++;
            }
        }

        $context->recordStage('fuzzy_dedup', $received, $received - $merged);

        return $context;
    }

    /**
     * Canon = highest search_volume (null treated as lowest), tie -> lowest id.
     * @param int[] $ids
     * @param array<int,?int> $volumeById
     */
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
