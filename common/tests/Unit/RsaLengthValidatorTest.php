<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\adgen\AdCopy;
use common\adgen\RsaLengthValidator;

/**
 * Phase 5: deterministic RSA length/count validation (§2.9 / §11). DB-less.
 * Headline <= 30 chars, description <= 90 chars (characters, not bytes);
 * 3..15 headlines, 2..4 descriptions.
 */
class RsaLengthValidatorTest extends Unit
{
    private RsaLengthValidator $validator;

    protected function _before(): void
    {
        $this->validator = new RsaLengthValidator();
    }

    public function testValidCopyPasses(): void
    {
        $copy = AdCopy::of(['Build a website', 'Free website builder', 'Start today'], ['Make your site now', 'Easy and fast']);
        $this->assertSame([], $this->validator->validate($copy));
        $this->assertTrue($this->validator->isValid($copy));
    }

    public function testHeadlineLengthBoundary(): void
    {
        $at = AdCopy::of([str_repeat('a', 30), 'ok', 'ok'], ['desc one', 'desc two']);
        $this->assertTrue($this->validator->isValid($at), '30 chars is the inclusive limit');

        $over = AdCopy::of([str_repeat('a', 31), 'ok', 'ok'], ['desc one', 'desc two']);
        $this->assertFalse($this->validator->isValid($over), '31 chars must fail');
    }

    public function testDescriptionLengthBoundary(): void
    {
        $at = AdCopy::of(['h1', 'h2', 'h3'], [str_repeat('d', 90), 'desc two']);
        $this->assertTrue($this->validator->isValid($at), '90 chars is the inclusive limit');

        $over = AdCopy::of(['h1', 'h2', 'h3'], [str_repeat('d', 91), 'desc two']);
        $this->assertFalse($this->validator->isValid($over), '91 chars must fail');
    }

    public function testHeadlineCountBounds(): void
    {
        $tooFew = AdCopy::of(['h1', 'h2'], ['d1', 'd2']);
        $this->assertFalse($this->validator->isValid($tooFew), 'fewer than 3 headlines fails');

        $tooMany = AdCopy::of(array_fill(0, 16, 'ok'), ['d1', 'd2']);
        $this->assertFalse($this->validator->isValid($tooMany), 'more than 15 headlines fails');

        $max = AdCopy::of(array_fill(0, 15, 'ok'), ['d1', 'd2']);
        $this->assertTrue($this->validator->isValid($max));
    }

    public function testDescriptionCountBounds(): void
    {
        $tooFew = AdCopy::of(['h1', 'h2', 'h3'], ['only one']);
        $this->assertFalse($this->validator->isValid($tooFew), 'fewer than 2 descriptions fails');

        $tooMany = AdCopy::of(['h1', 'h2', 'h3'], array_fill(0, 5, 'desc'));
        $this->assertFalse($this->validator->isValid($tooMany), 'more than 4 descriptions fails');
    }

    public function testMultibyteCountedAsCharactersNotBytes(): void
    {
        // 30 Cyrillic chars = 60 bytes but 30 characters -> valid.
        $at = AdCopy::of([str_repeat('я', 30), 'ok', 'ok'], ['описание раз', 'описание два']);
        $this->assertTrue($this->validator->isValid($at));

        $over = AdCopy::of([str_repeat('я', 31), 'ok', 'ok'], ['описание раз', 'описание два']);
        $this->assertFalse($this->validator->isValid($over));
    }
}
