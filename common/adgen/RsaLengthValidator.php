<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Deterministic RSA validation (§2.9): the spec requires length to be checked by
 * code, not trusted from the LLM. Google limits — headline <= 30 chars, description
 * <= 90 chars (characters, not bytes); 3..15 headlines, 2..4 descriptions.
 * Returns a list of human-readable violations (empty == valid).
 */
final class RsaLengthValidator
{
    public const MAX_HEADLINE_LENGTH = 30;
    public const MAX_DESCRIPTION_LENGTH = 90;
    public const MIN_HEADLINES = 3;
    public const MAX_HEADLINES = 15;
    public const MIN_DESCRIPTIONS = 2;
    public const MAX_DESCRIPTIONS = 4;

    /** @return string[] violations; empty means valid */
    public function validate(AdCopy $copy): array
    {
        $violations = [];

        $headlines = $copy->headlineTexts();
        $descriptions = $copy->descriptionTexts();

        $headlineCount = count($headlines);
        if ($headlineCount < self::MIN_HEADLINES || $headlineCount > self::MAX_HEADLINES) {
            $violations[] = "headline count {$headlineCount} outside " . self::MIN_HEADLINES . '..' . self::MAX_HEADLINES;
        }
        $descriptionCount = count($descriptions);
        if ($descriptionCount < self::MIN_DESCRIPTIONS || $descriptionCount > self::MAX_DESCRIPTIONS) {
            $violations[] = "description count {$descriptionCount} outside " . self::MIN_DESCRIPTIONS . '..' . self::MAX_DESCRIPTIONS;
        }

        foreach ($headlines as $index => $text) {
            $length = mb_strlen($text, 'UTF-8');
            if ($length > self::MAX_HEADLINE_LENGTH) {
                $violations[] = "headline #{$index} is {$length} chars (> " . self::MAX_HEADLINE_LENGTH . ')';
            }
        }
        foreach ($descriptions as $index => $text) {
            $length = mb_strlen($text, 'UTF-8');
            if ($length > self::MAX_DESCRIPTION_LENGTH) {
                $violations[] = "description #{$index} is {$length} chars (> " . self::MAX_DESCRIPTION_LENGTH . ')';
            }
        }

        return $violations;
    }

    public function isValid(AdCopy $copy): bool
    {
        return $this->validate($copy) === [];
    }
}
