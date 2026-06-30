<?php

declare(strict_types=1);

namespace common\services;

/**
 * Whole-word term matching used for brand and forbidden detection (§9 / §2.8).
 * One place for the rule (DRY): a term matches only as whole words (Unicode-aware
 * boundaries), so 'website' is never matched by 'site' in 'site.pro'. Terms are
 * DATA (kf_config_brand_term / kf_config_forbidden_term).
 */
final class TermMatcher
{
    /** @param string[] $terms already-normalized (lowercase) terms */
    public function matchesAny(string $normalizedKeyword, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            // (?<!letter/number) term (?!letter/number) — term may contain '.'/spaces.
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $normalizedKeyword) === 1) {
                return true;
            }
        }

        return false;
    }
}
