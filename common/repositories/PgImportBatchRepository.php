<?php

declare(strict_types=1);

namespace common\repositories;

use yii\db\Expression;
use yii\db\Connection;

/** PostgreSQL adapter for ImportBatchRepositoryInterface (§15). */
final class PgImportBatchRepository implements ImportBatchRepositoryInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function findOrCreate(int $projectId, string $fileName, string $fileHash): int
    {
        $existing = $this->db->createCommand(
            'SELECT id FROM kf_import_batch WHERE project_id = :p AND file_hash = :h',
            [':p' => $projectId, ':h' => $fileHash]
        )->queryScalar();

        if ($existing !== false && $existing !== null) {
            return (int) $existing;
        }

        return (int) $this->db->createCommand(
            'INSERT INTO kf_import_batch (project_id, file_name, file_hash, started_at)
             VALUES (:p, :name, :h, CURRENT_TIMESTAMP) RETURNING id',
            [':p' => $projectId, ':name' => $fileName, ':h' => $fileHash]
        )->queryScalar();
    }

    public function updateCounts(int $batchId, int $rowsTotal, int $rowsImported): void
    {
        $this->db->createCommand()->update(
            'kf_import_batch',
            ['rows_total' => $rowsTotal, 'rows_imported' => $rowsImported, 'finished_at' => new Expression('CURRENT_TIMESTAMP')],
            ['id' => $batchId]
        )->execute();
    }
}
