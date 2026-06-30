<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * RSA ad copy value object (§2.9): headlines + descriptions, each as {text, pin}
 * where pin is the Google Ads pinned position (1/2/3) or null. Stored verbatim as
 * kf_responsive_search_ad.headlines / .descriptions (jsonb). Plain DTO (no SOLID
 * ceremony, §12).
 */
final class AdCopy
{
    /**
     * @param array<int,array{text:string,pin:?int}> $headlines
     * @param array<int,array{text:string,pin:?int}> $descriptions
     */
    public function __construct(
        public array $headlines,
        public array $descriptions,
    ) {
    }

    /**
     * Convenience factory from plain texts; the headline at $pinnedHeadlineIndex is
     * pinned to position 1 (the brand headline, §2.9).
     *
     * @param string[] $headlineTexts
     * @param string[] $descriptionTexts
     */
    public static function of(array $headlineTexts, array $descriptionTexts, ?int $pinnedHeadlineIndex = null): self
    {
        $headlines = [];
        foreach (array_values($headlineTexts) as $index => $text) {
            $headlines[] = ['text' => $text, 'pin' => $index === $pinnedHeadlineIndex ? 1 : null];
        }
        $descriptions = array_map(static fn (string $text): array => ['text' => $text, 'pin' => null], array_values($descriptionTexts));

        return new self($headlines, $descriptions);
    }

    /** @return string[] */
    public function headlineTexts(): array
    {
        return array_map(static fn (array $headline): string => $headline['text'], $this->headlines);
    }

    /** @return string[] */
    public function descriptionTexts(): array
    {
        return array_map(static fn (array $description): string => $description['text'], $this->descriptions);
    }
}
