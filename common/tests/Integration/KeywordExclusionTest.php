<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\models\Keyword;

/**
 * Phase 7: the admin grid shows WHY a keyword is excluded (brand / already-used /
 * junk / merged / low-volume / forbidden) — §9 demonstrability. DB-less (attributes
 * set directly on the model).
 */
class KeywordExclusionTest extends Unit
{
    private function keyword(array $attributes): Keyword
    {
        $keyword = new Keyword();
        $keyword->setAttributes(array_merge(
            ['status' => 'new', 'is_brand' => false, 'is_forbidden' => false],
            $attributes
        ), false);

        return $keyword;
    }

    public function testActiveKeywordHasNoExclusionReason(): void
    {
        $keyword = $this->keyword(['status' => 'new']);
        $this->assertSame('', $keyword->exclusionReason());
        $this->assertTrue($keyword->isActive());
    }

    public function testAlreadyUsedAndBrandAreShownAsExcluded(): void
    {
        $this->assertSame('already used', $this->keyword(['status' => 'used'])->exclusionReason());
        $this->assertSame('brand', $this->keyword(['status' => 'new', 'is_brand' => true])->exclusionReason());
        $this->assertFalse($this->keyword(['is_brand' => true])->isActive());
    }

    public function testOtherExclusions(): void
    {
        $this->assertSame('junk → negative', $this->keyword(['status' => 'junk'])->exclusionReason());
        $this->assertSame('merged (duplicate)', $this->keyword(['status' => 'merged'])->exclusionReason());
        $this->assertSame('low volume', $this->keyword(['status' => 'low_volume'])->exclusionReason());
        $this->assertSame('forbidden', $this->keyword(['is_forbidden' => true])->exclusionReason());
    }
}
