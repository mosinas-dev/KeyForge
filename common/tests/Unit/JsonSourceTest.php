<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\sources\JsonSource;

/**
 * Phase 2: JSON source parsing. DB-less.
 */
class JsonSourceTest extends Unit
{
    private const MAP = [
        'raw_keyword' => 'keyword',
        'search_volume' => 'volume',
        'source_country' => 'country',
        'source_url' => 'url',
        'source_language' => 'language',
    ];

    /** @var string[] */
    private array $tempFiles = [];

    protected function _after(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function sourceFor(string $json, string $sourceType = 'json_src'): JsonSource
    {
        $path = tempnam(sys_get_temp_dir(), 'kf_json_');
        file_put_contents($path, $json);
        $this->tempFiles[] = $path;

        return new JsonSource($path, $sourceType, self::MAP);
    }

    public function testParsesArrayOfObjects(): void
    {
        $rows = iterator_to_array($this->sourceFor(
            '[{"keyword":"website builder","volume":49000,"country":"US","language":"en"}]'
        )->rows(), false);
        $this->assertCount(1, $rows);
        $this->assertSame('website builder', $rows[0]['raw_keyword']);
        $this->assertSame(49000, $rows[0]['search_volume']);
        $this->assertSame('US', $rows[0]['source_country']);
        $this->assertSame('en', $rows[0]['source_language']);
        $this->assertNull($rows[0]['source_url']);
    }

    public function testInvalidAndNegativeVolumeBecomeNull(): void
    {
        $rows = iterator_to_array($this->sourceFor(
            '[{"keyword":"a","volume":"n/a"},{"keyword":"b","volume":-3}]'
        )->rows(), false);
        $this->assertNull($rows[0]['search_volume']);
        $this->assertNull($rows[1]['search_volume']);
    }

    public function testMissingKeysAreNull(): void
    {
        $rows = iterator_to_array($this->sourceFor('[{"keyword":"only"}]')->rows(), false);
        $this->assertSame('only', $rows[0]['raw_keyword']);
        $this->assertNull($rows[0]['search_volume']);
        $this->assertNull($rows[0]['source_country']);
    }

    public function testEmptyArrayYieldsNoRows(): void
    {
        $this->assertCount(0, iterator_to_array($this->sourceFor('[]')->rows(), false));
    }

    public function testFingerprintIsSha256OfRawBytes(): void
    {
        $json = '[{"keyword":"a"}]';
        $this->assertSame(hash('sha256', $json), $this->sourceFor($json)->fingerprint());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($this->sourceFor('{not json')->rows(), false);
    }
}
