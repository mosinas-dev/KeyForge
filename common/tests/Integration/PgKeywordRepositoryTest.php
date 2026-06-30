<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\KeywordStatus;
use common\repositories\PgKeywordRepository;
use Yii;

/**
 * Phase 5.5: PgKeywordRepository encapsulates all kf_keyword SQL (§15.12) so stages
 * depend on KeywordRepositoryInterface, not on Connection. Integration (real pgsql).
 */
class PgKeywordRepositoryTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function repo(): PgKeywordRepository
    {
        return new PgKeywordRepository(Yii::$app->db);
    }

    private function hash(string $seed): string
    {
        return hash('sha256', $seed . '|' . $this->counter++);
    }

    public function testInsertIfNewIsIdempotent(): void
    {
        $repo = $this->repo();
        $importHash = $this->hash('idem');

        $first = $repo->insertIfNew(self::PROJECT_ID, 'ahrefs_paid', 'Website Builder', 'website builder', 49000, 'en', 'US', null, $importHash);
        $second = $repo->insertIfNew(self::PROJECT_ID, 'ahrefs_paid', 'Website Builder', 'website builder', 49000, 'en', 'US', null, $importHash);

        $this->assertTrue($first, 'first insert happens');
        $this->assertFalse($second, 're-insert of same import_hash is a no-op');
    }

    public function testFindActiveReturnsNewRowsWithFields(): void
    {
        $repo = $this->repo();
        $repo->insertIfNew(self::PROJECT_ID, 'test', 'kw a', 'kw a', 100, 'en', null, null, $this->hash('a'));

        $active = $repo->findActive(self::PROJECT_ID);
        $row = array_values(array_filter($active, static fn ($r) => $r['normalized_keyword'] === 'kw a'))[0];

        $this->assertArrayHasKey('id', $row);
        $this->assertSame('kw a', $row['normalized_keyword']);
        $this->assertSame('en', $row['detected_language']);
        $this->assertSame(100, $row['search_volume']);
    }

    public function testSettersAndMarkJunk(): void
    {
        $repo = $this->repo();
        $repo->insertIfNew(self::PROJECT_ID, 'test', 'kw b', 'kw b', null, null, null, null, $this->hash('b'));
        $id = (int) array_values(array_filter($repo->findActive(self::PROJECT_ID), static fn ($r) => $r['normalized_keyword'] === 'kw b'))[0]['id'];

        $repo->setLanguage($id, 'de');
        $repo->setIntent($id, 'commercial');
        $repo->setBrand($id);

        $row = Yii::$app->db->createCommand('SELECT detected_language, intent_class, is_brand, status FROM kf_keyword WHERE id = :id', [':id' => $id])->queryOne();
        $this->assertSame('de', $row['detected_language']);
        $this->assertSame('commercial', $row['intent_class']);
        $this->assertTrue((bool) $row['is_brand']);
        $this->assertSame(KeywordStatus::New->value, $row['status']);

        $repo->markJunk($id);
        $this->assertSame(
            KeywordStatus::Junk->value,
            Yii::$app->db->createCommand('SELECT status FROM kf_keyword WHERE id = :id', [':id' => $id])->queryScalar()
        );
        $this->assertSame([], array_filter($repo->findActive(self::PROJECT_ID), static fn ($r) => (int) $r['id'] === $id), 'junk no longer active');
    }
}
