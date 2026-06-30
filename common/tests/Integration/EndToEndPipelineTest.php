<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\adgen\RsaLengthValidator;
use common\services\KeywordPipelineService;
use common\sources\CsvSourceCatalog;
use Yii;

/**
 * Phase 8: full-pipeline acceptance (§9). The 4 sample CSVs go through
 * import -> clean -> prepare -> export via KeywordPipelineService (built by the DI
 * container), asserting every §9 criterion end-to-end on real PostgreSQL.
 */
class EndToEndPipelineTest extends Unit
{
    private const PROJECT_ID = 1;
    private const SAMPLES = [
        'ahrefs_organic_keywords.csv',
        'ahrefs_paid_keywords.csv',
        'google_ads_keywords.csv',
        'search_console_queries.csv',
    ];

    private function pipeline(): KeywordPipelineService
    {
        return Yii::createObject(KeywordPipelineService::class);
    }

    private function sampleDir(): string
    {
        return dirname(__DIR__, 3) . '/sample_data';
    }

    private function importAll(KeywordPipelineService $pipeline): void
    {
        foreach (self::SAMPLES as $file) {
            $pipeline->importSource(self::PROJECT_ID, CsvSourceCatalog::fromFile($this->sampleDir() . '/' . $file), $file);
        }
    }

    private function scalar(string $sql): mixed
    {
        return Yii::$app->db->createCommand($sql, [':p' => self::PROJECT_ID])->queryScalar();
    }

    public function testFullPipelineMeetsAcceptanceCriteria(): void
    {
        $pipeline = $this->pipeline();
        $this->importAll($pipeline);
        $pipeline->prepareCampaigns(self::PROJECT_ID);

        // §9 idempotency: re-importing a file adds 0 new rows.
        $reimport = $pipeline->importSource(
            self::PROJECT_ID,
            CsvSourceCatalog::fromFile($this->sampleDir() . '/ahrefs_organic_keywords.csv'),
            'ahrefs_organic_keywords.csv'
        );
        $this->assertSame(0, $reimport->stageStats()['ingest']['out'], 're-import = 0 new');

        // §9 junk -> negatives (not deleted).
        $negatives = Yii::$app->db->createCommand(
            'SELECT keyword_text FROM kf_negative_keyword WHERE project_id = :p', [':p' => self::PROJECT_ID]
        )->queryColumn();
        $this->assertContains('????', $negatives);
        $this->assertContains('asdkjh qwe', $negatives);

        // §9 brands flagged (site pro builder / site.pro отзывы).
        $this->assertGreaterThanOrEqual(2, (int) $this->scalar('SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND is_brand'));

        // §9 dedup: 'website builder' collapsed to a single canon (rest merged).
        $this->assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND normalized_keyword = 'website builder' AND status <> 'merged'"
        ));
        $this->assertGreaterThan(0, (int) $this->scalar(
            "SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND normalized_keyword = 'website builder' AND status = 'merged'"
        ));

        // §9 groups strictly monolingual, target_url matches language.
        $mismatch = (int) $this->scalar(
            "SELECT COUNT(*) FROM kf_ad_group WHERE project_id = :p AND target_url <> 'https://site.pro/' || language"
        );
        $this->assertSame(0, $mismatch, 'every group target_url matches its language');
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM kf_ad_group WHERE project_id = :p'));

        // §9 RSA within limits: no headline > 30, no description > 90 chars.
        $this->assertSame(0, (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM kf_responsive_search_ad r
             JOIN kf_ad_group g ON g.id = r.ad_group_id
             , LATERAL jsonb_array_elements(r.headlines) h
             WHERE g.project_id = :p AND char_length(h->>'text') > :max",
            [':p' => self::PROJECT_ID, ':max' => RsaLengthValidator::MAX_HEADLINE_LENGTH]
        )->queryScalar(), 'all headlines <= 30');
        $this->assertSame(0, (int) Yii::$app->db->createCommand(
            "SELECT COUNT(*) FROM kf_responsive_search_ad r
             JOIN kf_ad_group g ON g.id = r.ad_group_id
             , LATERAL jsonb_array_elements(r.descriptions) d
             WHERE g.project_id = :p AND char_length(d->>'text') > :max",
            [':p' => self::PROJECT_ID, ':max' => RsaLengthValidator::MAX_DESCRIPTION_LENGTH]
        )->queryScalar(), 'all descriptions <= 90');

        // §9 export: valid files with content.
        $result = $pipeline->export(self::PROJECT_ID);
        $this->assertArrayHasKey('campaigns.csv', $result->files);
        $this->assertArrayHasKey('negatives.csv', $result->files);
        $this->assertStringContainsString('Campaign', $result->files['campaigns.csv']);
        $this->assertStringContainsString('????', $result->files['negatives.csv']);
        $this->assertGreaterThan(0, $result->adGroupCount);
    }
}
