<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\JunkClassifier;

/**
 * Phase 3: junk detection (§2.2 / §11). DB-less, operates on the already-normalized
 * keyword. Returns a reason for junk, or null for a clean keyword.
 */
class JunkClassifierTest extends Unit
{
    private JunkClassifier $classifier;

    protected function _before(): void
    {
        $this->classifier = new JunkClassifier();
    }

    public function testEmptyAndTooShort(): void
    {
        $this->assertSame(JunkClassifier::REASON_TOO_SHORT, $this->classifier->classify(''));
        $this->assertSame(JunkClassifier::REASON_TOO_SHORT, $this->classifier->classify('ab'));
    }

    public function testNumericOnly(): void
    {
        $this->assertSame(JunkClassifier::REASON_NUMERIC_ONLY, $this->classifier->classify('12345'));
        $this->assertSame(JunkClassifier::REASON_NUMERIC_ONLY, $this->classifier->classify('123 456'));
    }

    public function testSpecialCharactersOnly(): void
    {
        $this->assertSame(JunkClassifier::REASON_SPECIAL_ONLY, $this->classifier->classify('????'));
        $this->assertSame(JunkClassifier::REASON_SPECIAL_ONLY, $this->classifier->classify('!!! ---'));
    }

    public function testStopWordsOnly(): void
    {
        $this->assertSame(JunkClassifier::REASON_STOPWORDS_ONLY, $this->classifier->classify('the for'));
    }

    public function testGibberish(): void
    {
        // 'asdkjh' has a 5+ consonant run -> gibberish (the §9 sample junk).
        $this->assertSame(JunkClassifier::REASON_GIBBERISH, $this->classifier->classify('asdkjh qwe'));
    }

    /**
     * @dataProvider cleanKeywords
     */
    public function testCleanKeywordsReturnNull(string $keyword): void
    {
        $this->assertNull($this->classifier->classify($keyword), "'{$keyword}' must be clean");
    }

    public static function cleanKeywords(): array
    {
        return [
            ['website builder'],
            ['free website builder'],        // 'free' is a commercial marker, not junk
            ['конструктор сайтов'],          // cyrillic real keyword
            ['homepage baukasten'],          // german, has 'st' runs but < 5
            ['website erstellen lassen'],    // 'rst' run = 3, clean
            ['how to create a website'],     // informational, but NOT all stop-words
        ];
    }
}
