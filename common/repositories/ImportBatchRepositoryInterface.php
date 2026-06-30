<?php

declare(strict_types=1);

namespace common\repositories;

/** Repository for kf_import_batch (per-file import bookkeeping, §2.1 / §15). */
interface ImportBatchRepositoryInterface
{
    /** Reuse the batch for a re-imported file (UNIQUE(project_id, file_hash)) or create it. Returns its id. */
    public function findOrCreate(int $projectId, string $fileName, string $fileHash): int;

    public function updateCounts(int $batchId, int $rowsTotal, int $rowsImported): void;
}
