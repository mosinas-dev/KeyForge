<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\adgen\AdCopyRequest;
use common\adgen\RsaLengthValidator;
use common\adgen\TemplateAdCopyGenerator;
use common\services\LanguageDetector;

/**
 * Phase 5: deterministic template ad-copy generator (§2.9). DB-less.
 * Produces RSA copy that passes RsaLengthValidator, in the requested language,
 * with the brand headline pinned to position 1.
 */
class TemplateAdCopyGeneratorTest extends Unit
{
    private TemplateAdCopyGenerator $generator;
    private RsaLengthValidator $validator;

    protected function _before(): void
    {
        $this->generator = new TemplateAdCopyGenerator();
        $this->validator = new RsaLengthValidator();
    }

    public function testGeneratesValidEnglishCopyWithPinnedBrand(): void
    {
        $request = new AdCopyRequest('en', 'https://site.pro/en', ['website builder', 'free website builder'], 'Site.pro');
        $copy = $this->generator->generate($request);

        $this->assertSame([], $this->validator->validate($copy), 'generated copy must satisfy RSA limits');
        $this->assertSame('Site.pro', $copy->headlines[0]['text']);
        $this->assertSame(1, $copy->headlines[0]['pin'], 'brand headline pinned to position 1');
    }

    public function testGeneratesValidRussianCopyInLanguage(): void
    {
        $request = new AdCopyRequest('ru', 'https://site.pro/ru', ['конструктор сайтов', 'создать сайт'], 'Site.pro');
        $copy = $this->generator->generate($request);

        $this->assertTrue($this->validator->isValid($copy));
        $combined = implode(' ', array_merge($copy->headlineTexts(), $copy->descriptionTexts()));
        $this->assertSame('ru', (new LanguageDetector())->detect($combined), 'copy must read as the group language');
    }

    public function testTruncatesLongKeywordToHeadlineLimit(): void
    {
        $longKeyword = str_repeat('website builder ', 5); // ~80 chars
        $copy = $this->generator->generate(new AdCopyRequest('en', 'https://site.pro/en', [$longKeyword]));

        foreach ($copy->headlineTexts() as $headline) {
            $this->assertLessThanOrEqual(RsaLengthValidator::MAX_HEADLINE_LENGTH, mb_strlen($headline));
        }
    }

    public function testWorksWithoutBrand(): void
    {
        $copy = $this->generator->generate(new AdCopyRequest('en', 'https://site.pro/en', ['website builder']));

        $this->assertTrue($this->validator->isValid($copy));
        foreach ($copy->headlines as $headline) {
            $this->assertNull($headline['pin'], 'no brand -> nothing pinned');
        }
    }
}
