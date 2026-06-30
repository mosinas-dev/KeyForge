<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\BrandMatcher;

/**
 * Phase 3: brand detection (§9). DB-less. Whole-word match so generic keywords
 * ('website builder') are NOT flagged just because they contain 'site'.
 */
class BrandMatcherTest extends Unit
{
    private const TERMS = ['site.pro', 'site pro', 'sitepro', 'site.pro builder', 'site.pro отзывы'];

    private BrandMatcher $matcher;

    protected function _before(): void
    {
        $this->matcher = new BrandMatcher();
    }

    public function testMatchesBrandKeywords(): void
    {
        $this->assertTrue($this->matcher->isBrand('site pro builder', self::TERMS));
        $this->assertTrue($this->matcher->isBrand('site.pro отзывы', self::TERMS));
        $this->assertTrue($this->matcher->isBrand('sitepro', self::TERMS));
    }

    public function testDoesNotMatchGenericKeywords(): void
    {
        $this->assertFalse($this->matcher->isBrand('website builder', self::TERMS), "'site' inside 'website' must not match");
        $this->assertFalse($this->matcher->isBrand('free website builder', self::TERMS));
        $this->assertFalse($this->matcher->isBrand('конструктор сайтов', self::TERMS));
    }

    public function testDoesNotMatchBrandTokenInsideLongerWord(): void
    {
        $this->assertFalse($this->matcher->isBrand('siteproxy tools', self::TERMS), 'word-boundary stops sitepro<->siteproxy');
    }

    public function testEmptyTermsNeverMatch(): void
    {
        $this->assertFalse($this->matcher->isBrand('site pro builder', []));
    }
}
