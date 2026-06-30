<?php

declare(strict_types=1);

namespace common\tests\Integration;

use Codeception\Test\Unit;
use common\pipeline\PipelineContext;
use common\pipeline\stages\FuzzyDedupStage;
use common\services\KeywordNormalizer;
use Yii;

/**
 * Phase 3: fuzzy dedup (§2.5 / §11). Word-order + diacritics collapse via dedupKey;
 * typos via pg_trgm similarity >= 0.85. Canon = max search_volume (tie -> lowest id).
 * Different languages are never merged. Integration (real pgsql + pg_trgm).
 */
class FuzzyDedupStageTest extends Unit
{
    private const PROJECT_ID = 1;
    private int $counter = 0;

    private function insert(string $normalized, ?string $language, ?int $volume): int
    {
        $hash = hash('sha256', 'dedup-test|' . $normalized . '|' . $this->counter++);

        return (int) Yii::$app->db->createCommand(
            "INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, detected_language, search_volume, import_hash, status)
             VALUES (:p, 'test', :raw, :norm, :lang, :vol, :hash, 'new') RETURNING id",
            [':p' => self::PROJECT_ID, ':raw' => $normalized, ':norm' => $normalized,
             ':lang' => $language, ':vol' => $volume, ':hash' => $hash]
        )->queryScalar();
    }

    /** @return array{status:string,merged_into_keyword_id:?int} */
    private function row(int $id): array
    {
        $row = Yii::$app->db->createCommand(
            'SELECT status, merged_into_keyword_id FROM kf_keyword WHERE id = :id', [':id' => $id]
        )->queryOne();

        return ['status' => $row['status'], 'merged_into_keyword_id' => $row['merged_into_keyword_id'] === null ? null : (int) $row['merged_into_keyword_id']];
    }

    private function assertCanon(int $id): void
    {
        $row = $this->row($id);
        $this->assertSame('new', $row['status'], "#{$id} should be the canon (active)");
        $this->assertNull($row['merged_into_keyword_id']);
    }

    private function assertMergedInto(int $id, int $canonId): void
    {
        $row = $this->row($id);
        $this->assertSame('merged', $row['status'], "#{$id} should be merged");
        $this->assertSame($canonId, $row['merged_into_keyword_id']);
    }

    private function runStage(): void
    {
        (new FuzzyDedupStage(Yii::$app->db, new KeywordNormalizer()))->run(new PipelineContext(self::PROJECT_ID));
    }

    public function testExactDuplicatesMergeToMaxVolumeCanonWithTieBreak(): void
    {
        $a = $this->insert('website builder', 'en', 49000);
        $b = $this->insert('website builder', 'en', 41000);
        $c = $this->insert('website builder', 'en', 49000); // ties A on volume -> A wins (lower id)

        $this->runStage();

        $this->assertCanon($a);
        $this->assertMergedInto($b, $a);
        $this->assertMergedInto($c, $a);
    }

    public function testWordOrderVariantsMerge(): void
    {
        $d = $this->insert('website builder free', 'en', 100);
        $e = $this->insert('free website builder', 'en', 5000);

        $this->runStage();

        $this->assertCanon($e);
        $this->assertMergedInto($d, $e);
    }

    public function testDiacriticVariantsMergeViaDedupKey(): void
    {
        $f = $this->insert('gratis', 'pt', 100);
        $g = $this->insert('grátis', 'pt', 200);

        $this->runStage();

        $this->assertCanon($g);
        $this->assertMergedInto($f, $g);
    }

    public function testIncrementalRecanonizationKeepsChainFlat(): void
    {
        // Multi-pass (like multi-file import): a higher-volume duplicate arrives
        // later and becomes the new canon — earlier merged rows must re-point to it,
        // never to a now-merged intermediate.
        $first = $this->insert('free website builder', 'en', 27000);
        $this->runStage();

        $wordOrder = $this->insert('website builder free', 'en', 27000); // merges into $first (tie -> lower id)
        $this->runStage();
        $this->assertMergedInto($wordOrder, $first);

        $bigger = $this->insert('free website builder', 'en', 38000); // new canon over $first
        $this->runStage();

        $this->assertCanon($bigger);
        $this->assertMergedInto($first, $bigger);
        $this->assertMergedInto($wordOrder, $bigger);
    }

    public function testFuzzyTypoMergesViaTrgm(): void
    {
        $similarity = (float) Yii::$app->db->createCommand(
            "SELECT similarity('ecommerce website builder', 'ecomerce website builder')"
        )->queryScalar();
        $this->assertGreaterThanOrEqual(0.85, $similarity, 'precondition: this typo pair is above threshold');

        $correct = $this->insert('ecommerce website builder', 'en', 800);
        $typo = $this->insert('ecomerce website builder', 'en', 50);

        $this->runStage();

        $this->assertCanon($correct);
        $this->assertMergedInto($typo, $correct);
    }

    public function testDifferentLanguagesAreNotMerged(): void
    {
        $en = $this->insert('web', 'en', 10);
        $de = $this->insert('web', 'de', 10);

        $this->runStage();

        $this->assertCanon($en);
        $this->assertCanon($de);
    }

    public function testBelowThresholdAndDifferentKeyNotMerged(): void
    {
        $a = $this->insert('website builder', 'en', 49000);
        $b = $this->insert('websit builder', 'en', 100); // sim 0.824 < 0.85, different dedupKey

        $this->runStage();

        $this->assertCanon($a);
        $this->assertCanon($b);
    }
}
