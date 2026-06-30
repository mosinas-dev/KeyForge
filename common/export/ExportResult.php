<?php

declare(strict_types=1);

namespace common\export;

/**
 * Result of an export run (§14.15): the produced files plus metadata, instead of a
 * bare array.
 */
final readonly class ExportResult
{
    /** @param array<string,string> $files file name => content */
    public function __construct(
        public array $files,
        public int $adGroupCount,
        public int $negativeKeywordCount,
    ) {
    }
}
