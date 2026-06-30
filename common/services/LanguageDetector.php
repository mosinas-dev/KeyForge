<?php

declare(strict_types=1);

namespace common\services;

/**
 * Deterministic language detection (§2.3) for the project's 5 languages.
 *
 * Rather than CLD3/fastText (native deps, non-deterministic confidence), we use a
 * predictable, fully testable heuristic: any Cyrillic letter -> 'ru'; otherwise
 * score latin tokens by distinctive marker words and take the unique winner.
 * No markers or a tie -> null, so the caller keeps source_language as the fallback
 * (§11: short/translit/ambiguous). Trade-off accepted for predictability; a real
 * ML detector is a deferred swap behind this same method.
 */
final class LanguageDetector
{
    /** Distinctive marker words per latin language (shared words like site/web/online stay neutral). */
    private const MARKERS = [
        'en' => ['website', 'builder', 'free', 'best', 'create', 'small', 'business',
                 'landing', 'page', 'drag', 'drop', 'ecommerce', 'ai', 'reseller', 'build', 'how', 'what'],
        'de' => ['erstellen', 'lassen', 'kostenlos', 'kostenlose', 'baukasten', 'seite', 'günstig'],
        'pt' => ['criar', 'loja', 'gratis'],
        'es' => ['crear', 'tienda', 'pagina', 'gratis'],
    ];

    public function detect(string $normalizedKeyword): ?string
    {
        if ($normalizedKeyword === '') {
            return null;
        }
        // Lowercase so detection works on raw text too (e.g. Title-Cased ad copy),
        // not just pre-normalized keywords; markers are stored lowercase.
        $text = mb_strtolower($normalizedKeyword, 'UTF-8');
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            return 'ru';
        }

        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scores = ['en' => 0, 'de' => 0, 'pt' => 0, 'es' => 0];
        foreach ($tokens as $token) {
            foreach (self::MARKERS as $language => $markerWords) {
                if (in_array($token, $markerWords, true)) {
                    $scores[$language]++;
                }
            }
        }

        $max = max($scores);
        if ($max === 0) {
            return null; // no distinctive markers
        }
        $winners = array_keys($scores, $max, true);

        return count($winners) === 1 ? $winners[0] : null; // tie -> ambiguous -> fallback
    }
}
