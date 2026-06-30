<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\GadsPrepStage;
use common\services\TermMatcher;
use Yii;

/**
 * Phase 4: GAds-prep (§2.7–2.8 / §11). Operates on post-cleaning state.
 *  - used (google_ads in cluster) -> status='used', and used beats opportunity;
 *  - competitor gap (ahrefs_paid, not used) -> is_opportunity;
 *  - forbidden / brand -> excluded from groups;
 *  - STAG groups per (intent, language), monolingual, target_url from the map;
 *  - no empty groups; single-keyword group OK; language out of map excluded;
 *  - idempotent (upsert).
 */
class GadsPrepStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insert(string $normalized, array $overrides = []): int
    {
        $attrs = array_merge([
            'source_type' => 'ahrefs_organic',
            'detected_language' => 'en',
            'search_volume' => 1000,
            'status' => 'new',
            'intent_class' => 'commercial',
            'is_brand' => false,
            'is_forbidden' => false,
            'merged_into' => null,
        ], $overrides);

        $hash = hash('sha256', 'gads-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, search_volume,
                 intent_class, is_brand, is_forbidden, merged_into_keyword_id, import_hash, status)
             VALUES (:p, :src, :kw, :kw, :lang, :vol, :intent, :brand, :forbidden, :merged, :hash, :status)
             RETURNING id",
            [
                ':p' => self::PROJECT_ID, ':src' => $attrs['source_type'], ':kw' => $normalized,
                ':lang' => $attrs['detected_language'], ':vol' => $attrs['search_volume'],
                ':intent' => $attrs['intent_class'], ':brand' => $attrs['is_brand'],
                ':forbidden' => $attrs['is_forbidden'], ':merged' => $attrs['merged_into'],
                ':hash' => $hash, ':status' => $attrs['status'],
            ]
        )->queryScalar();
    }

    /** @return array{status:string,is_opportunity:bool,is_forbidden:bool} */
    private function flags(int $id): array
    {
        $row = Yii::$app->db->createCommand(
            'SELECT status, is_opportunity, is_forbidden FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryOne();

        return [
            'status' => $row['status'],
            'is_opportunity' => (bool) $row['is_opportunity'],
            'is_forbidden' => (bool) $row['is_forbidden'],
        ];
    }

    /** @return array<string,mixed>|false */
    private function adGroup(string $intent, string $language)
    {
        return Yii::$app->db->createCommand(
            'SELECT * FROM kf_ad_group WHERE project_id = :p AND intent_class = :i AND language = :l',
            [':p' => self::PROJECT_ID, ':i' => $intent, ':l' => $language]
        )->queryOne();
    }

    private function adGroupCount(): int
    {
        return (int) Yii::$app->db->createCommand(
            'SELECT COUNT(*) FROM kf_ad_group WHERE project_id = :p', [':p' => self::PROJECT_ID]
        )->queryScalar();
    }

    private function runStage(): void
    {
        (new GadsPrepStage(Yii::$app->db, new TermMatcher()))->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testUsedKeywordIsMarkedUsedAndNotOpportunity(): void
    {
        $canon = $this->insert('website builder', ['source_type' => 'ahrefs_organic']);
        $this->insert('website builder', ['source_type' => 'google_ads', 'status' => 'merged', 'merged_into' => $canon]);
        $this->insert('website builder', ['source_type' => 'ahrefs_paid', 'status' => 'merged', 'merged_into' => $canon]);

        $this->runStage();

        $flags = $this->flags($canon);
        $this->assertSame('used', $flags['status'], 'in google_ads -> used');
        $this->assertFalse($flags['is_opportunity'], 'used beats opportunity (§11)');
    }

    public function testCompetitorGapIsMarkedOpportunity(): void
    {
        $canon = $this->insert('landing page builder', ['source_type' => 'ahrefs_organic']);
        $this->insert('landing page builder', ['source_type' => 'ahrefs_paid', 'status' => 'merged', 'merged_into' => $canon]);

        $this->runStage();

        $flags = $this->flags($canon);
        $this->assertSame('new', $flags['status']);
        $this->assertTrue($flags['is_opportunity'], 'ahrefs_paid not used -> opportunity (§2.7)');
    }

    public function testForbiddenIsFlaggedAndExcludedFromGroups(): void
    {
        $forbidden = $this->insert('casino website builder'); // matches seeded forbidden 'casino'

        $this->runStage();

        $this->assertTrue($this->flags($forbidden)['is_forbidden']);
        $this->assertFalse($this->adGroup('commercial', 'en'), 'forbidden-only language must not form a group');
    }

    public function testBrandIsExcludedFromGroups(): void
    {
        $this->insert('site pro builder', ['is_brand' => true]);

        $this->runStage();

        $this->assertFalse($this->adGroup('commercial', 'en'), 'brand-only language must not form a group');
    }

    public function testCreatesMonolingualGroupsWithLanguageUrlAndSkipsInformational(): void
    {
        $this->insert('website builder', ['detected_language' => 'en']);
        $this->insert('конструктор сайтов', ['detected_language' => 'ru']);
        $this->insert('what is a website builder', ['detected_language' => 'en', 'intent_class' => 'informational']);

        $this->runStage();

        $en = $this->adGroup('commercial', 'en');
        $ru = $this->adGroup('commercial', 'ru');
        $this->assertNotFalse($en);
        $this->assertNotFalse($ru);
        $this->assertSame('https://site.pro/en', $en['target_url']);
        $this->assertSame('https://site.pro/ru', $ru['target_url']);
        $this->assertSame('en', $en['language'], 'groups are monolingual (§9)');
        $this->assertFalse($this->adGroup('informational', 'en'), 'informational intent is not sent to Ads');
    }

    public function testSingleKeywordLanguageStillCreatesGroup(): void
    {
        $this->insert('homepage baukasten', ['detected_language' => 'de']);

        $this->runStage();

        $this->assertNotFalse($this->adGroup('commercial', 'de'), 'a one-keyword group is still created (§11)');
    }

    public function testLanguageNotInUrlMapIsExcluded(): void
    {
        $this->insert('site builder', ['detected_language' => 'fr']); // 'fr' not in language->url map

        $this->runStage();

        $this->assertSame(0, $this->adGroupCount(), 'language without a target_url must not form a group (§11)');
    }

    public function testIdempotentReRunDoesNotDuplicateGroups(): void
    {
        $this->insert('website builder', ['detected_language' => 'en']);
        $this->runStage();
        $this->runStage();

        $this->assertSame(1, $this->adGroupCount());
    }
}
