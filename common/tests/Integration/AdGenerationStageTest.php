<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\adgen\AdCopy;
use common\adgen\AdCopyGenerator;
use common\adgen\AdCopyRequest;
use common\adgen\RsaLengthValidator;
use common\adgen\TemplateAdCopyGenerator;
use common\pipeline\PipelineContext;
use common\pipeline\stages\AdGenerationStage;
use common\repositories\PgAdGroupRepository;
use common\repositories\PgConfigRepository;
use common\repositories\PgKeywordRepository;
use common\services\LanguageDetector;
use RuntimeException;
use Yii;

/**
 * Phase 5: RSA generation per group (§2.9 / §11). Generate -> validate (length +
 * language) -> regenerate invalid up to N times -> persist to
 * kf_responsive_search_ad. Bad/empty generator output never crashes the stage.
 */
class AdGenerationStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insertAdGroup(string $intent, string $language, string $url): int
    {
        return (int) Yii::$app->db->createCommand(
            'INSERT INTO kf_ad_group (project_id, group_name, intent_class, language, target_url)
             VALUES (:p, :name, :i, :l, :u) RETURNING id',
            [':p' => self::PROJECT_ID, ':name' => strtoupper($language) . '_' . $intent, ':i' => $intent, ':l' => $language, ':u' => $url]
        )->queryScalar();
    }

    private function insertKeyword(string $normalized, string $language, string $intent): void
    {
        $hash = hash('sha256', 'adgen|' . $normalized . '|' . $this->counter++);
        Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, search_volume,
                 intent_class, import_hash, status)
             VALUES (:p, 'ahrefs_organic', :kw, :kw, :l, 1000, :i, :hash, 'new')",
            [':p' => self::PROJECT_ID, ':kw' => $normalized, ':l' => $language, ':i' => $intent, ':hash' => $hash]
        )->execute();
    }

    /** @return array<string,mixed>|false */
    private function rsaFor(int $adGroupId)
    {
        return Yii::$app->db->createCommand(
            'SELECT * FROM kf_responsive_search_ad WHERE ad_group_id = :g', [':g' => $adGroupId]
        )->queryOne();
    }

    private function stage(AdCopyGenerator $generator): AdGenerationStage
    {
        return new AdGenerationStage(
            new PgKeywordRepository(Yii::$app->db),
            new PgAdGroupRepository(Yii::$app->db),
            new PgConfigRepository(Yii::$app->db),
            $generator,
            new RsaLengthValidator(),
            new LanguageDetector()
        );
    }

    public function testGeneratesValidRsaWithPinnedBrand(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');
        $this->insertKeyword('website builder', 'en', 'commercial');
        $this->insertKeyword('free website builder', 'en', 'commercial');

        $this->stage(new TemplateAdCopyGenerator())->run(new PipelineContext(self::PROJECT_ID));

        $rsa = $this->rsaFor($group);
        $this->assertNotFalse($rsa);
        $this->assertSame('valid', $rsa['validation_status']);

        $headlines = json_decode($rsa['headlines'], true);
        $descriptions = json_decode($rsa['descriptions'], true);
        $this->assertGreaterThanOrEqual(RsaLengthValidator::MIN_HEADLINES, count($headlines));
        $this->assertGreaterThanOrEqual(RsaLengthValidator::MIN_DESCRIPTIONS, count($descriptions));
        $this->assertSame('Site.pro', $headlines[0]['text'], 'brand headline pinned first');
        $this->assertSame(1, $headlines[0]['pin']);
        foreach ($headlines as $headline) {
            $this->assertLessThanOrEqual(RsaLengthValidator::MAX_HEADLINE_LENGTH, mb_strlen($headline['text']));
        }
    }

    public function testInvalidLengthIsRegeneratedThenMarkedFailedNotCrash(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');

        $alwaysTooLong = new class implements AdCopyGenerator {
            public function generate(AdCopyRequest $request): AdCopy
            {
                return AdCopy::of([str_repeat('x', 50), 'ok', 'ok'], ['desc one', 'desc two']);
            }
        };

        $this->stage($alwaysTooLong)->run(new PipelineContext(self::PROJECT_ID));

        $this->assertSame('failed', $this->rsaFor($group)['validation_status'], 'invalid length -> failed, no crash');
    }

    public function testBrokenGeneratorDoesNotCrash(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');

        $broken = new class implements AdCopyGenerator {
            public function generate(AdCopyRequest $request): AdCopy
            {
                throw new RuntimeException('LLM returned broken JSON');
            }
        };

        $this->stage($broken)->run(new PipelineContext(self::PROJECT_ID));

        $this->assertSame('failed', $this->rsaFor($group)['validation_status'], 'broken generator -> failed, no crash (§11)');
    }

    public function testWrongLanguageIsRejected(): void
    {
        $group = $this->insertAdGroup('commercial', 'ru', 'https://site.pro/ru');

        $englishForRussianGroup = new class implements AdCopyGenerator {
            public function generate(AdCopyRequest $request): AdCopy
            {
                return AdCopy::of(['free website builder', 'best website builder', 'create your site'], ['build a free website today', 'easy website builder online']);
            }
        };

        $this->stage($englishForRussianGroup)->run(new PipelineContext(self::PROJECT_ID));

        $this->assertSame('failed', $this->rsaFor($group)['validation_status'], 'response language != group language -> reject (§11)');
    }

    public function testIdempotentReRunKeepsOneRsaPerGroup(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');
        $this->insertKeyword('website builder', 'en', 'commercial');

        $this->stage(new TemplateAdCopyGenerator())->run(new PipelineContext(self::PROJECT_ID));
        $this->stage(new TemplateAdCopyGenerator())->run(new PipelineContext(self::PROJECT_ID));

        $count = (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM kf_responsive_search_ad WHERE ad_group_id = :g', [':g' => $group]
        )->queryScalar();
        $this->assertSame(1, $count);
    }
}
