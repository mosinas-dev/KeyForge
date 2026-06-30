<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\ImportHashCalculator;
use common\services\KeywordNormalizer;
use common\sources\KeywordSourceProvider;
use yii\db\Connection;
use yii\db\Expression;

/**
 * Ingest stage (§2.1): reads a source, normalizes each keyword, and inserts into
 * kf_keyword idempotently (ADR 0006). Re-importing the same file adds 0 rows
 * (ON CONFLICT (project_id, import_hash) DO NOTHING). Records a kf_import_batch.
 *
 * Source-provided language seeds detected_language; the language-detect stage
 * (Phase 3) refines it or keeps this value as the fallback (§11).
 */
final class IngestStage implements PipelineStage
{
    public function __construct(
        private KeywordSourceProvider $source,
        private string $fileName,
        private Connection $db,
        private ImportHashCalculator $hashCalculator,
        private KeywordNormalizer $normalizer,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $projectId = $context->projectId;
        $sourceType = $this->source->sourceType();
        $fileHash = $this->source->fingerprint();

        $batchId = $this->findOrCreateBatch($projectId, $fileHash);

        $rowsTotal = 0;
        $rowsImported = 0;
        foreach ($this->source->rows() as $row) {
            $rowsTotal++;
            $rowsImported += $this->insertKeyword($projectId, $sourceType, $fileHash, $row);
        }

        $this->db->createCommand()->update(
            'kf_import_batch',
            ['rows_total' => $rowsTotal, 'rows_imported' => $rowsImported, 'finished_at' => new Expression('CURRENT_TIMESTAMP')],
            ['id' => $batchId]
        )->execute();

        $context->importBatchId = $batchId;
        $context->recordStage('ingest', $rowsTotal, $rowsImported);

        return $context;
    }

    /** @param array{raw_keyword:string,search_volume:?int,source_country:?string,source_url:?string,source_language:?string} $row */
    private function insertKeyword(int $projectId, string $sourceType, string $fileHash, array $row): int
    {
        $importHash = $this->hashCalculator->keywordHash($sourceType, $fileHash, $row['raw_keyword']);

        // ON CONFLICT DO NOTHING -> execute() returns 1 when inserted, 0 when the
        // (project_id, import_hash) already exists; that count IS rows_imported.
        return $this->db->createCommand(
            'INSERT INTO kf_keyword
                (project_id, source_type, raw_keyword, normalized_keyword, search_volume,
                 detected_language, source_country, source_url, import_hash, status)
             VALUES (:project_id, :source_type, :raw, :norm, :vol, :lang, :country, :url, :hash, :status)
             ON CONFLICT (project_id, import_hash) DO NOTHING',
            [
                ':project_id' => $projectId,
                ':source_type' => $sourceType,
                ':raw' => $row['raw_keyword'],
                ':norm' => $this->normalizer->normalize($row['raw_keyword']),
                ':vol' => $row['search_volume'],
                ':lang' => $row['source_language'],
                ':country' => $row['source_country'],
                ':url' => $row['source_url'],
                ':hash' => $importHash,
                ':status' => 'new',
            ]
        )->execute();
    }

    /** Reuse the batch for a re-imported file (UNIQUE(project_id, file_hash)) so it stays idempotent. */
    private function findOrCreateBatch(int $projectId, string $fileHash): int
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
            [':p' => $projectId, ':name' => $this->fileName, ':h' => $fileHash]
        )->queryScalar();
    }
}
