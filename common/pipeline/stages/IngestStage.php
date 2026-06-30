<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\ImportBatchRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\services\ImportHashCalculator;
use common\services\KeywordNormalizer;
use common\sources\KeywordSourceProvider;

/**
 * Ingest stage (§2.1): reads a source, normalizes each keyword, and inserts into
 * kf_keyword idempotently (ADR 0006). Re-importing the same file adds 0 rows
 * (insertIfNew is a no-op on a known import_hash). Records a kf_import_batch.
 *
 * Source-provided language seeds detected_language; the language-detect stage
 * (Phase 3) refines it or keeps this value as the fallback (§11).
 */
final class IngestStage implements PipelineStage
{
    public function __construct(
        private KeywordSourceProvider $source,
        private string $fileName,
        private KeywordRepositoryInterface $keywords,
        private ImportBatchRepositoryInterface $batches,
        private ImportHashCalculator $hashCalculator,
        private KeywordNormalizer $normalizer,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $sourceType = $this->source->sourceType();
        $fileHash = $this->source->fingerprint();
        $batchId = $this->batches->findOrCreate($context->projectId, $this->fileName, $fileHash);

        $rowsTotal = 0;
        $rowsImported = 0;
        foreach ($this->source->rows() as $row) {
            $rowsTotal++;
            $inserted = $this->keywords->insertIfNew(
                $context->projectId,
                $sourceType,
                $row['raw_keyword'],
                $this->normalizer->normalize($row['raw_keyword']),
                $row['search_volume'],
                $row['source_language'],
                $row['source_country'],
                $row['source_url'],
                $this->hashCalculator->keywordHash($sourceType, $fileHash, $row['raw_keyword'])
            );
            if ($inserted) {
                $rowsImported++;
            }
        }

        $this->batches->updateCounts($batchId, $rowsTotal, $rowsImported);

        $context->importBatchId = $batchId;
        $context->recordStage('ingest', $rowsTotal, $rowsImported);

        return $context;
    }
}
