<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\KeywordNormalizer;

/**
 * Phase 2: canonical keyword normalization (§2.1). DB-less.
 * normalize() = strip zero-width, unify whitespace, collapse, trim, lowercase.
 * Token-sort for fuzzy-dedup is a Phase 3 concern (kept out of here on purpose).
 */
class KeywordNormalizerTest extends Unit
{
    private KeywordNormalizer $normalizer;

    protected function _before(): void
    {
        $this->normalizer = new KeywordNormalizer();
    }

    public function testTrimsAndLowercases(): void
    {
        $this->assertSame('website builder', $this->normalizer->normalize('  Website Builder  '));
    }

    public function testCollapsesInternalWhitespace(): void
    {
        $this->assertSame('website builder', $this->normalizer->normalize('website   builder'));
        $this->assertSame('website builder free', $this->normalizer->normalize("website\tbuilder\nfree"));
    }

    public function testNormalizesUnicodeSpacesToRegularSpace(): void
    {
        // U+00A0 non-breaking space between the words.
        $this->assertSame('website builder', $this->normalizer->normalize("website\u{00A0}builder"));
    }

    public function testStripsZeroWidthCharacters(): void
    {
        // U+200B zero-width space inside the word must vanish.
        $this->assertSame('website', $this->normalizer->normalize("web\u{200B}site"));
    }

    public function testMultibyteLowercase(): void
    {
        $this->assertSame('сайт конструктор', $this->normalizer->normalize('Сайт Конструктор'));
    }

    public function testEmptyAndWhitespaceOnlyBecomeEmptyString(): void
    {
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertSame('', $this->normalizer->normalize("   \t \u{00A0} "));
    }

    // --- dedupKey(): token-sorted, diacritic-stripped key for fuzzy-dedup (§2.5) ---

    public function testDedupKeySortsTokens(): void
    {
        $this->assertSame('builder website', $this->normalizer->dedupKey('website builder'));
    }

    public function testDedupKeyIsWordOrderInvariant(): void
    {
        $this->assertSame(
            $this->normalizer->dedupKey('website builder free'),
            $this->normalizer->dedupKey('free website builder'),
            'word-order variants must share a dedup key'
        );
    }

    public function testDedupKeyStripsLatinDiacritics(): void
    {
        $this->assertSame('gratis', $this->normalizer->dedupKey('grátis'));
        $this->assertSame($this->normalizer->dedupKey('gratis'), $this->normalizer->dedupKey('grátis'));
    }

    public function testDedupKeyKeepsCyrillic(): void
    {
        $this->assertSame('конструктор сайтов', $this->normalizer->dedupKey('конструктор сайтов'));
    }
}
