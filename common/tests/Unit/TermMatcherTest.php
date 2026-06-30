<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\TermMatcher;

/**
 * Phase 3/4: whole-word term matching for brand + forbidden (§9 / §2.8). DB-less.
 * 'website builder' must NOT match because it contains 'site'.
 */
class TermMatcherTest extends Unit
{
    private const TERMS = ['site.pro', 'site pro', 'sitepro', 'site.pro builder', 'site.pro отзывы'];

    private TermMatcher $matcher;

    protected function _before(): void
    {
        $this->matcher = new TermMatcher();
    }

    public function testMatchesTermKeywords(): void
    {
        $this->assertTrue($this->matcher->matchesAny('site pro builder', self::TERMS));
        $this->assertTrue($this->matcher->matchesAny('site.pro отзывы', self::TERMS));
        $this->assertTrue($this->matcher->matchesAny('sitepro', self::TERMS));
    }

    public function testDoesNotMatchGenericKeywords(): void
    {
        $this->assertFalse($this->matcher->matchesAny('website builder', self::TERMS), "'site' inside 'website' must not match");
        $this->assertFalse($this->matcher->matchesAny('free website builder', self::TERMS));
        $this->assertFalse($this->matcher->matchesAny('конструктор сайтов', self::TERMS));
    }

    public function testDoesNotMatchTermInsideLongerWord(): void
    {
        $this->assertFalse($this->matcher->matchesAny('siteproxy tools', self::TERMS), 'word-boundary stops sitepro<->siteproxy');
    }

    public function testForbiddenStyleTerms(): void
    {
        $this->assertTrue($this->matcher->matchesAny('casino website builder', ['casino', 'porn']));
        $this->assertFalse($this->matcher->matchesAny('website builder', ['casino', 'porn']));
    }

    public function testEmptyTermsNeverMatch(): void
    {
        $this->assertFalse($this->matcher->matchesAny('site pro builder', []));
    }
}
