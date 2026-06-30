<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\LanguageDetectStage;
use common\repositories\PgKeywordRepository;
use common\services\LanguageDetector;
use Yii;

/**
 * Phase 3: language detection by text, with fallback to the source-seeded value
 * (§2.3 / §11). Map-validation of unsupported languages is deferred to grouping
 * (Phase 4), where language->url is actually used.
 */
class LanguageDetectStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insertKeyword(string $normalized, ?string $sourceLanguage): int
    {
        $hash = hash('sha256', 'lang-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, import_hash, status)
             VALUES (:p, 'test', :raw, :norm, :lang, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':raw' => $normalized, ':norm' => $normalized, ':lang' => $sourceLanguage, ':hash' => $hash]
        )->queryScalar();
    }

    private function language(int $id): ?string
    {
        $value = Yii::$app->db->createCommand(
            'SELECT detected_language FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryScalar();

        return $value === false ? null : $value;
    }

    private function runStage(): void
    {
        (new LanguageDetectStage(new PgKeywordRepository(Yii::$app->db), new LanguageDetector()))->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testDetectsByTextAndOverwrites(): void
    {
        $en = $this->insertKeyword('free website builder', null);
        $ru = $this->insertKeyword('конструктор сайтов', null);
        $pt = $this->insertKeyword('criar site gratis', null);

        $this->runStage();

        $this->assertSame('en', $this->language($en));
        $this->assertSame('ru', $this->language($ru));
        $this->assertSame('pt', $this->language($pt));
    }

    public function testFallsBackToSourceLanguageWhenUndetermined(): void
    {
        // 'sdelat sait' (translit) -> detector returns null -> keep source-seeded 'ru'.
        $translit = $this->insertKeyword('sdelat sait', 'ru');

        $this->runStage();

        $this->assertSame('ru', $this->language($translit), 'undetermined detection must keep source_language (§11)');
    }
}
