<?php

declare(strict_types=1);

namespace common\sources;

use InvalidArgumentException;

/**
 * Builds the right KeywordSourceProvider for a file: CsvSource for .csv, JsonSource
 * for .json (OCP — same code path supports both source formats, §13 lists API
 * sources as the next adapter). The column map per source_type is shared from
 * CsvSourceCatalog, so a JSON file just needs the same keys as the CSV headers.
 */
final class KeywordSourceFactory
{
    /** Infer source_type from the file-name stem (CLI use). */
    public static function fromFile(string $filePath): KeywordSourceProvider
    {
        return self::build($filePath, CsvSourceCatalog::sourceTypeForFile($filePath));
    }

    /** Build a provider for an explicit source_type (admin upload). */
    public static function build(string $filePath, string $sourceType): KeywordSourceProvider
    {
        $columnMap = CsvSourceCatalog::columnMapFor($sourceType);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv' => new CsvSource($filePath, $sourceType, $columnMap),
            'json' => new JsonSource($filePath, $sourceType, $columnMap),
            default => throw new InvalidArgumentException("Unsupported source extension '.{$extension}' (expected .csv or .json)"),
        };
    }
}
