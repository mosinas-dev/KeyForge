<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\repositories\PgAdGroupRepository;
use common\repositories\PgConfigRepository;
use common\repositories\PgImportBatchRepository;
use common\repositories\PgNegativeKeywordRepository;
use Yii;

/**
 * Phase 5.5: PG adapters for the config / ad-group / negative / import-batch
 * aggregates (§15). Integration (real pgsql; uses the seeded kf_config_*).
 */
class PgRepositoriesTest extends Unit
{
    private const PROJECT_ID = 1;

    public function testConfigRepositoryReadsSeededRules(): void
    {
        $config = new PgConfigRepository(Yii::$app->db);

        $this->assertContains('site.pro', $config->brandTerms(self::PROJECT_ID));
        $this->assertContains('casino', $config->forbiddenTerms(self::PROJECT_ID));
        $this->assertSame('https://site.pro/en', $config->languageUrl(self::PROJECT_ID, 'en'));
        $this->assertNull($config->languageUrl(self::PROJECT_ID, 'fr'), 'unmapped language -> null');

        $threshold = $config->volumeThreshold(self::PROJECT_ID, 'en');
        $this->assertSame(0.25, $threshold['percentile']);
        $this->assertNull($config->volumeThreshold(self::PROJECT_ID, 'fr'), 'no threshold for unmapped language');
    }

    public function testAdGroupRepositoryUpsertAndRsa(): void
    {
        $repo = new PgAdGroupRepository(Yii::$app->db);
        $repo->upsertGroup(self::PROJECT_ID, 'EN_commercial', 'commercial', 'en', 'https://site.pro/en');
        $repo->upsertGroup(self::PROJECT_ID, 'EN_commercial', 'commercial', 'en', 'https://site.pro/en'); // idempotent

        $groups = array_values(array_filter($repo->allGroups(self::PROJECT_ID), static fn ($g) => $g['language'] === 'en' && $g['intent_class'] === 'commercial'));
        $this->assertCount(1, $groups, 'upsert is idempotent');
        $groupId = $groups[0]['id'];

        $repo->replaceRsa($groupId, [['text' => 'Site.pro', 'pin' => 1]], [['text' => 'Desc', 'pin' => null]], 'valid');
        $repo->replaceRsa($groupId, [['text' => 'Site.pro', 'pin' => 1], ['text' => 'H2', 'pin' => null]], [['text' => 'Desc one', 'pin' => null]], 'valid');

        $copy = $repo->findValidRsaCopy($groupId);
        $this->assertNotNull($copy);
        $this->assertCount(2, $copy['headlines'], 'RSA replaced, not duplicated');
        $this->assertSame('Site.pro', $copy['headlines'][0]['text']);
    }

    public function testNegativeKeywordRepository(): void
    {
        $repo = new PgNegativeKeywordRepository(Yii::$app->db);
        $repo->addIgnoringDuplicate(self::PROJECT_ID, '????', 'special_only', null);
        $repo->addIgnoringDuplicate(self::PROJECT_ID, '????', 'special_only', null); // duplicate ignored

        $texts = $repo->allTexts(self::PROJECT_ID);
        $this->assertSame(1, count(array_filter($texts, static fn ($t) => $t === '????')));
    }

    public function testImportBatchRepository(): void
    {
        $repo = new PgImportBatchRepository(Yii::$app->db);
        $fileHash = hash('sha256', 'batch-repo-test');

        $first = $repo->findOrCreate(self::PROJECT_ID, 'f.csv', $fileHash);
        $second = $repo->findOrCreate(self::PROJECT_ID, 'f.csv', $fileHash);
        $this->assertSame($first, $second, 're-import reuses the batch');

        $repo->updateCounts($first, 12, 11);
        $row = Yii::$app->db->createCommand('SELECT rows_total, rows_imported FROM kf_import_batch WHERE id = :id', [':id' => $first])->queryOne();
        $this->assertSame(12, (int) $row['rows_total']);
        $this->assertSame(11, (int) $row['rows_imported']);
    }
}
