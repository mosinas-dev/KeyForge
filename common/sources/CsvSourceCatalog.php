<?php

declare(strict_types=1);

namespace common\sources;

use InvalidArgumentException;

/**
 * Maps the four known sample sources to a source_type + column map, and builds a
 * CsvSource from a file path (source_type inferred from the file name). Keeps the
 * per-source column knowledge in one place; the parser (CsvSource) stays generic.
 */
final class CsvSourceCatalog
{
    /** source_type => canonical field => CSV header (null = field absent in this source). */
    private const COLUMN_MAPS = [
        'ahrefs_organic' => [
            'raw_keyword' => 'keyword', 'search_volume' => 'volume',
            'source_country' => 'country', 'source_url' => 'ranking_url', 'source_language' => null,
        ],
        'ahrefs_paid' => [
            'raw_keyword' => 'keyword', 'search_volume' => 'volume',
            'source_country' => 'country', 'source_url' => null, 'source_language' => null,
        ],
        'google_ads' => [
            'raw_keyword' => 'keyword', 'search_volume' => 'avg_monthly_searches',
            'source_country' => null, 'source_url' => 'final_url', 'source_language' => 'language',
        ],
        'search_console' => [
            'raw_keyword' => 'query', 'search_volume' => 'impressions',
            'source_country' => 'country', 'source_url' => null, 'source_language' => null,
        ],
    ];

    /** File-name stem (without extension) => source_type. Same column map serves CSV and JSON. */
    private const FILENAME_TO_TYPE = [
        'ahrefs_organic_keywords' => 'ahrefs_organic',
        'ahrefs_paid_keywords' => 'ahrefs_paid',
        'google_ads_keywords' => 'google_ads',
        'search_console_queries' => 'search_console',
    ];

    public static function fromFile(string $filePath): CsvSource
    {
        $sourceType = self::sourceTypeForFile($filePath);

        return new CsvSource($filePath, $sourceType, self::COLUMN_MAPS[$sourceType]);
    }

    /** @return string[] known source types (for the admin upload dropdown) */
    public static function sourceTypes(): array
    {
        return array_keys(self::COLUMN_MAPS);
    }

    /** Source type inferred from the file-name stem, regardless of .csv/.json extension. */
    public static function sourceTypeForFile(string $filePath): string
    {
        $stem = pathinfo($filePath, PATHINFO_FILENAME);
        if (!isset(self::FILENAME_TO_TYPE[$stem])) {
            throw new InvalidArgumentException("Unknown source file '{$stem}'. Known: " . implode(', ', array_keys(self::FILENAME_TO_TYPE)));
        }

        return self::FILENAME_TO_TYPE[$stem];
    }

    /** @return array<string,?string> */
    public static function columnMapFor(string $sourceType): array
    {
        if (!isset(self::COLUMN_MAPS[$sourceType])) {
            throw new InvalidArgumentException("Unknown source_type '{$sourceType}'");
        }

        return self::COLUMN_MAPS[$sourceType];
    }
}
