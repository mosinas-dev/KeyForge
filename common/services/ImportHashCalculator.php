<?php

declare(strict_types=1);

namespace common\services;

/**
 * Computes import identity hashes (ADR 0006) — the single place this formula lives.
 *
 *   file_hash   = sha256(file contents)            -> stored in kf_import_batch.file_hash
 *   import_hash = sha256(source_type|file_hash|raw_keyword)  -> kf_keyword.import_hash
 *
 * Backs UNIQUE(project_id, import_hash): re-importing the same file yields the same
 * hashes (idempotent, 0 new rows); two different files with the same keyword get
 * different file_hash -> different import_hash (§11). Uses the RAW keyword.
 */
final class ImportHashCalculator
{
    private const SEPARATOR = '|';

    public function fileHash(string $fileContents): string
    {
        return hash('sha256', $fileContents);
    }

    public function keywordHash(string $sourceType, string $fileHash, string $rawKeyword): string
    {
        return hash('sha256', $sourceType . self::SEPARATOR . $fileHash . self::SEPARATOR . $rawKeyword);
    }
}
