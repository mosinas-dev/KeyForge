<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\LanguageDetector;

/**
 * Phase 3: deterministic language detection (§2.3 / §11). DB-less.
 *
 * Design choice: a deterministic script + marker-word detector rather than CLD3/
 * fastText — predictable, testable, no native extension. Cyrillic -> ru; latin
 * scored by distinctive marker words; ambiguous/unknown -> null (caller falls back
 * to source_language). Covers the project's 5 languages (ru/en/pt/es/de).
 */
class LanguageDetectorTest extends Unit
{
    private LanguageDetector $detector;

    protected function _before(): void
    {
        $this->detector = new LanguageDetector();
    }

    /**
     * @dataProvider samples
     */
    public function testDetect(?string $expected, string $keyword): void
    {
        $this->assertSame($expected, $this->detector->detect($keyword), "detect('{$keyword}')");
    }

    public static function samples(): array
    {
        return [
            'english' => ['en', 'free website builder'],
            'english markers' => ['en', 'small business website builder'],
            'russian cyrillic' => ['ru', 'конструктор сайтов'],
            'russian verbs' => ['ru', 'сделать лендинг'],
            'german' => ['de', 'website erstellen lassen'],
            'german baukasten' => ['de', 'homepage baukasten'],
            'portuguese' => ['pt', 'criar site gratis'],
            'portuguese loja' => ['pt', 'criar loja virtual'],
            'spanish' => ['es', 'crear pagina web'],
            'spanish gratis' => ['es', 'crear pagina web gratis'],
            // mixed: cyrillic present -> ru wins (the brand query)
            'mixed cyrillic wins' => ['ru', 'site.pro отзывы'],
            // transliterated russian -> no markers -> null -> fallback to source
            'translit -> null' => [null, 'sdelat sait'],
            // no distinctive markers -> null
            'ambiguous -> null' => [null, 'web online'],
        ];
    }
}
