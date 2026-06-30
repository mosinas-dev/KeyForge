<?php

declare(strict_types=1);

namespace common\services;

/**
 * Canonical keyword normalization (§2.1) — the single place keywords are cleaned
 * (DRY, §12). Produces the value stored as kf_keyword.normalized_keyword.
 *
 * Steps: strip zero-width chars -> unify all whitespace (incl NBSP/tabs/newlines)
 * to a single space -> trim -> lowercase (multibyte-safe).
 *
 * NOTE: normalize() stays word-order-sensitive (it's what's stored/displayed).
 * The token-sorted, accent-stripped form used for fuzzy-dedup (§2.5) is a separate
 * method, dedupKey(), kept here too so all keyword canonicalization lives in one place.
 */
final class KeywordNormalizer
{
    /** Zero-width / format characters that carry no meaning but break matching. */
    private const ZERO_WIDTH_CHARS = ["\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"];

    public function normalize(string $rawKeyword): string
    {
        $value = str_replace(self::ZERO_WIDTH_CHARS, '', $rawKeyword);
        // [\s\p{Z}] covers ASCII whitespace (\s) AND Unicode separators like NBSP (\p{Z}).
        $value = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $value);
        $value = trim($value);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Dedup key (§2.5): token-sorted + diacritics stripped, so word-order variants
     * ("website builder" == "builder website") and accent variants ("grátis" ==
     * "gratis") collapse to the same key. Cyrillic is preserved (only Latin
     * combining marks are removed). Input is expected already normalized.
     */
    public function dedupKey(string $normalizedKeyword): string
    {
        $unaccented = $this->stripDiacritics($normalizedKeyword);
        $tokens = preg_split('/\s+/', trim($unaccented), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /**
     * Strip Latin accents only. Decompose (NFD), drop combining marks that follow a
     * Latin base letter, then recompose (NFC). This leaves Cyrillic intact — e.g.
     * 'й' decomposes to 'и'+breve but isn't Latin, so the breve is kept and NFC
     * restores 'й' ('сайтов' stays 'сайтов', not 'саитов').
     */
    private function stripDiacritics(string $value): string
    {
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        if ($decomposed === false) {
            return $value;
        }
        $latinFolded = (string) preg_replace('/([A-Za-z])\p{Mn}+/u', '$1', $decomposed);
        $recomposed = \Normalizer::normalize($latinFolded, \Normalizer::FORM_C);

        return $recomposed === false ? $latinFolded : $recomposed;
    }
}
