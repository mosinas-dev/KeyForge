<?php

declare(strict_types=1);

namespace common\services;

/**
 * Brand detection (§9): does a normalized keyword contain any configured brand
 * term as whole words? Whole-word match (Unicode-aware boundaries) so 'website'
 * is never flagged by the 'site' part of 'site.pro'. Brand terms are DATA
 * (kf_config_brand_term), passed in by the stage.
 */
final class BrandMatcher
{
    /** @param string[] $brandTerms already-normalized (lowercase) brand terms */
    public function isBrand(string $normalizedKeyword, array $brandTerms): bool
    {
        foreach ($brandTerms as $term) {
            if ($term === '') {
                continue;
            }
            // (?<!letter/number) term (?!letter/number) — whole-word, term may contain '.'/spaces.
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $normalizedKeyword) === 1) {
                return true;
            }
        }

        return false;
    }
}
