<?php

declare(strict_types=1);

namespace common\services;

/**
 * Intent classification (§2.4). Pure. Two buckets: 'commercial' (incl.
 * transactional) vs 'informational'. Only commercial goes to Ads (§2.4), so the
 * default for an unmarked keyword is commercial — generic product terms still run.
 *
 * Informational question markers take precedence on conflict (§11): 'how to create
 * a website' is a question -> informational, even though 'create' is commercial.
 */
final class IntentClassifier
{
    public const COMMERCIAL = 'commercial';
    public const INFORMATIONAL = 'informational';

    /** Question/guide markers across the project's languages. */
    private const INFORMATIONAL_MARKERS = [
        'how', 'what', 'why', 'when', 'where', 'guide', 'tutorial', 'tips',  // en
        'wie', 'was', 'warum',                                              // de
        'como', 'cómo', 'qué',                                             // es/pt
        'как', 'что', 'почему', 'зачем',                                    // ru
    ];

    public function classify(string $normalizedKeyword): string
    {
        $tokens = preg_split('/\s+/', $normalizedKeyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (in_array($token, self::INFORMATIONAL_MARKERS, true)) {
                return self::INFORMATIONAL;
            }
        }

        return self::COMMERCIAL;
    }
}
