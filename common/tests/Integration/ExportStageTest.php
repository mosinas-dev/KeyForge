<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\export\GoogleAdsEditorExporter;
use common\pipeline\stages\ExportStage;
use common\repositories\PgAdGroupRepository;
use common\repositories\PgKeywordRepository;
use common\repositories\PgNegativeKeywordRepository;
use League\Csv\Reader;
use Yii;

/**
 * Phase 6: ExportStage reads the prepared project and builds the Google Ads Editor
 * files (§2.10). Integration (DB) — formatting itself is unit-tested on the exporter.
 */
class ExportStageTest extends Unit
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

    private function insertEligibleKeyword(string $normalized, string $language, string $intent): void
    {
        $hash = hash('sha256', 'export|' . $normalized . '|' . $this->counter++);
        Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, search_volume,
                 intent_class, import_hash, status)
             VALUES (:p, 'ahrefs_organic', :kw, :kw, :l, 1000, :i, :hash, 'new')",
            [':p' => self::PROJECT_ID, ':kw' => $normalized, ':l' => $language, ':i' => $intent, ':hash' => $hash]
        )->execute();
    }

    /** @param string[] $headlines @param string[] $descriptions */
    private function insertValidRsa(int $adGroupId, array $headlines, array $descriptions): void
    {
        $toJson = static fn (array $texts): string => json_encode(
            array_map(static fn (string $t): array => ['text' => $t, 'pin' => null], $texts),
            JSON_UNESCAPED_UNICODE
        );
        Yii::$app->db->createCommand(
            "INSERT INTO kf_responsive_search_ad (ad_group_id, headlines, descriptions, validation_status)
             VALUES (:g, CAST(:h AS jsonb), CAST(:d AS jsonb), 'valid')",
            [':g' => $adGroupId, ':h' => $toJson($headlines), ':d' => $toJson($descriptions)]
        )->execute();
    }

    private function insertNegative(string $text): void
    {
        Yii::$app->db->createCommand(
            "INSERT INTO kf_negative_keyword (project_id, keyword_text, reason) VALUES (:p, :t, 'special_only')",
            [':p' => self::PROJECT_ID, ':t' => $text]
        )->execute();
    }

    private function records(string $csv): array
    {
        $reader = Reader::fromString($csv);
        $reader->setHeaderOffset(0);

        return iterator_to_array($reader->getRecords(), false);
    }

    public function testBuildsCampaignsAndNegativesFromDb(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');
        $this->insertEligibleKeyword('website builder', 'en', 'commercial');
        $this->insertValidRsa($group, ['Site.pro', 'Easy Website Builder', 'Start Free Today'], ['Build your site fast.', 'No code needed.']);
        $this->insertNegative('????');

        $files = (new ExportStage(
            new PgAdGroupRepository(Yii::$app->db),
            new PgKeywordRepository(Yii::$app->db),
            new PgNegativeKeywordRepository(Yii::$app->db),
            new GoogleAdsEditorExporter()
        ))->export(self::PROJECT_ID)->files;

        $campaignRows = $this->records($files['campaigns.csv']);
        $keywordRow = array_values(array_filter($campaignRows, static fn ($r) => $r['Keyword'] !== ''))[0];
        $adRow = array_values(array_filter($campaignRows, static fn ($r) => $r['Headline 1'] !== ''))[0];

        $this->assertSame('website builder', $keywordRow['Keyword']);
        $this->assertSame('SP_EN', $keywordRow['Campaign'], 'campaign derived per language');
        $this->assertSame('EN_commercial', $keywordRow['Ad Group']);
        $this->assertSame('https://site.pro/en', $keywordRow['Final URL']);
        $this->assertSame('Site.pro', $adRow['Headline 1']);

        $negativeRows = $this->records($files['negatives.csv']);
        $this->assertSame('????', $negativeRows[0]['Keyword']);
    }

    public function testExcludesNonEligibleKeywordsFromExport(): void
    {
        $group = $this->insertAdGroup('commercial', 'en', 'https://site.pro/en');
        $this->insertEligibleKeyword('website builder', 'en', 'commercial');
        $this->insertValidRsa($group, ['Site.pro', 'H2', 'H3'], ['D1 description', 'D2 description']);
        // a used/merged keyword (status != new) must not be exported
        Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword (project_id, source_type, raw_keyword, normalized_keyword, detected_language,
                intent_class, import_hash, status)
             VALUES (:p, 'google_ads', 'used kw', 'used kw', 'en', 'commercial', :h, 'used')",
            [':p' => self::PROJECT_ID, ':h' => hash('sha256', 'export-used|' . $this->counter++)]
        )->execute();

        $files = (new ExportStage(
            new PgAdGroupRepository(Yii::$app->db),
            new PgKeywordRepository(Yii::$app->db),
            new PgNegativeKeywordRepository(Yii::$app->db),
            new GoogleAdsEditorExporter()
        ))->export(self::PROJECT_ID)->files;
        $keywords = array_column($this->records($files['campaigns.csv']), 'Keyword');

        $this->assertContains('website builder', $keywords);
        $this->assertNotContains('used kw', $keywords, 'status=used must not be exported');
    }
}
