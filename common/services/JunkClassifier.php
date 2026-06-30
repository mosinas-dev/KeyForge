<?php

declare(strict_types=1);

namespace common\services;

/**
 * Junk detection (§2.2). Pure: takes an already-normalized keyword and returns a
 * junk reason, or null if clean. Junk is NOT deleted — the caller routes it to
 * kf_negative_keyword so the marketer gets minus-words for free.
 *
 * Stop-words and the gibberish heuristic (a 5+ latin-consonant run) are linguistic
 * constants kept in code; business rules (brands/forbidden) live in kf_config_*.
 */
final class JunkClassifier
{
    public const REASON_TOO_SHORT = 'too_short';
    public const REASON_NUMERIC_ONLY = 'numeric_only';
    public const REASON_SPECIAL_ONLY = 'special_only';
    public const REASON_STOPWORDS_ONLY = 'stopwords_only';
    public const REASON_GIBBERISH = 'gibberish';

    private const MIN_LENGTH = 3;

    /** Tokens that carry no search intent on their own (multilingual, conservative). */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'at', 'is', 'are',
        'how', 'what', 'why', 'with', 'my', 'your',
        'и', 'в', 'во', 'на', 'с', 'со', 'по', 'для', 'как', 'что',
    ];

    public function classify(string $normalizedKeyword): ?string
    {
        if (mb_strlen($normalizedKeyword) < self::MIN_LENGTH) {
            return self::REASON_TOO_SHORT;
        }
        if (preg_match('/^[\d\s]+$/u', $normalizedKeyword)) {
            return self::REASON_NUMERIC_ONLY;
        }
        if (!preg_match('/[\p{L}\p{N}]/u', $normalizedKeyword)) {
            return self::REASON_SPECIAL_ONLY;
        }
        if ($this->isAllStopWords($normalizedKeyword)) {
            return self::REASON_STOPWORDS_ONLY;
        }
        // 5+ consecutive latin consonants = unpronounceable -> gibberish ('asdkjh').
        // Only latin consonants, so cyrillic/other scripts are untouched.
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{5,}/', $normalizedKeyword)) {
            return self::REASON_GIBBERISH;
        }

        return null;
    }

    private function isAllStopWords(string $normalizedKeyword): bool
    {
        $tokens = preg_split('/\s+/', trim($normalizedKeyword), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $token) {
            if (!in_array($token, self::STOP_WORDS, true)) {
                return false;
            }
        }

        return true;
    }
}
