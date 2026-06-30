<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\IntentClassifier;

/**
 * Phase 3: intent classification (§2.4 / §11). DB-less.
 * Only commercial intent goes to Ads; informational (questions/guides) is filtered.
 * Default (no marker) = commercial. Conflict -> informational question wins.
 */
class IntentClassifierTest extends Unit
{
    private IntentClassifier $classifier;

    protected function _before(): void
    {
        $this->classifier = new IntentClassifier();
    }

    /**
     * @dataProvider cases
     */
    public function testClassify(string $expected, string $keyword): void
    {
        $this->assertSame($expected, $this->classifier->classify($keyword), "classify('{$keyword}')");
    }

    public static function cases(): array
    {
        return [
            // free is a commercial marker, not informational (§11 boundary)
            'free commercial' => [IntentClassifier::COMMERCIAL, 'free website builder'],
            'best commercial' => [IntentClassifier::COMMERCIAL, 'best website builder'],
            // no markers -> default commercial (generic product keyword still goes to Ads)
            'no marker default' => [IntentClassifier::COMMERCIAL, 'website builder'],
            'cyrillic generic' => [IntentClassifier::COMMERCIAL, 'конструктор сайтов'],
            // question phrases -> informational
            'what is informational' => [IntentClassifier::INFORMATIONAL, 'what is a website builder'],
            // conflict: 'how' (info) + 'create' (commercial) -> informational wins
            'conflict how wins' => [IntentClassifier::INFORMATIONAL, 'how to create a website'],
        ];
    }
}
