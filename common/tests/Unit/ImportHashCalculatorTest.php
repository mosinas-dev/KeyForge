<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\services\ImportHashCalculator;

/**
 * Phase 2: import_hash semantics (ADR 0006). DB-less.
 * import_hash = sha256(source_type | file_hash | raw_keyword); file_hash = sha256(contents).
 * Re-import of same file -> same hashes (idempotent); different files, same keyword
 * -> different hashes (§11). Uses RAW keyword (pre-normalization).
 */
class ImportHashCalculatorTest extends Unit
{
    private ImportHashCalculator $calc;

    protected function _before(): void
    {
        $this->calc = new ImportHashCalculator();
    }

    public function testFileHashIsSha256Hex(): void
    {
        $hash = $this->calc->fileHash("col1,col2\na,b\n");
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function testFileHashIsDeterministicAndContentSensitive(): void
    {
        $this->assertSame($this->calc->fileHash('same'), $this->calc->fileHash('same'));
        $this->assertNotSame($this->calc->fileHash('a'), $this->calc->fileHash('b'));
    }

    public function testKeywordHashIsSha256HexAndDeterministic(): void
    {
        $a = $this->calc->keywordHash('ahrefs_paid', 'f' . str_repeat('0', 63), 'website builder');
        $b = $this->calc->keywordHash('ahrefs_paid', 'f' . str_repeat('0', 63), 'website builder');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertSame($a, $b);
    }

    public function testDifferentFilesSameKeywordProduceDifferentHashes(): void
    {
        $fileA = $this->calc->fileHash('file A contents');
        $fileB = $this->calc->fileHash('file B contents');
        $this->assertNotSame(
            $this->calc->keywordHash('google_ads', $fileA, 'site builder'),
            $this->calc->keywordHash('google_ads', $fileB, 'site builder'),
            'different files with the same keyword must hash differently (§11)'
        );
    }

    public function testDifferentSourceTypeProducesDifferentHash(): void
    {
        $fileHash = $this->calc->fileHash('x');
        $this->assertNotSame(
            $this->calc->keywordHash('ahrefs_paid', $fileHash, 'kw'),
            $this->calc->keywordHash('google_ads', $fileHash, 'kw')
        );
    }

    public function testRawKeywordIsCaseSensitive(): void
    {
        $fileHash = $this->calc->fileHash('x');
        $this->assertNotSame(
            $this->calc->keywordHash('src', $fileHash, 'Website Builder'),
            $this->calc->keywordHash('src', $fileHash, 'website builder'),
            'import_hash uses the RAW keyword (ADR 0006), so casing differs'
        );
    }
}
