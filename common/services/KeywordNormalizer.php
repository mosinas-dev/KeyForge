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
 * NOTE: token-sort for fuzzy-dedup (§2.5, "website builder" == "builder website")
 * is intentionally NOT here — it belongs to the dedup stage (Phase 3).
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
}
