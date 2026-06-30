<?php

declare(strict_types=1);

namespace common\tests\Unit;

use Codeception\Test\Unit;
use common\sources\CsvSource;
use common\sources\JsonSource;
use common\sources\KeywordSourceFactory;

/**
 * Phase 2/7: the factory picks CsvSource (.csv) or JsonSource (.json) and shares the
 * source_type column map. DB-less.
 */
class KeywordSourceFactoryTest extends Unit
{
    public function testBuildsCsvForCsvExtension(): void
    {
        $source = KeywordSourceFactory::build('/tmp/x/ahrefs_paid_keywords.csv', 'ahrefs_paid');
        $this->assertInstanceOf(CsvSource::class, $source);
        $this->assertSame('ahrefs_paid', $source->sourceType());
    }

    public function testBuildsJsonForJsonExtension(): void
    {
        $source = KeywordSourceFactory::build('/tmp/x/whatever.json', 'ahrefs_organic');
        $this->assertInstanceOf(JsonSource::class, $source);
        $this->assertSame('ahrefs_organic', $source->sourceType());
    }

    public function testFromFileInfersTypeAndFormat(): void
    {
        $source = KeywordSourceFactory::fromFile(dirname(__DIR__, 3) . '/sample_data/ahrefs_organic_keywords.json');
        $this->assertInstanceOf(JsonSource::class, $source);
        $this->assertSame('ahrefs_organic', $source->sourceType());
    }

    public function testUnsupportedExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeywordSourceFactory::build('/tmp/x/data.txt', 'ahrefs_organic');
    }
}
