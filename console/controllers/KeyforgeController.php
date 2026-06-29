<?php

declare(strict_types=1);

namespace console\controllers;

use common\pipeline\PipelineContext;
use common\pipeline\stages\IngestStage;
use common\services\ImportHashCalculator;
use common\services\KeywordNormalizer;
use common\sources\CsvSourceCatalog;
use InvalidArgumentException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * KeyForge pipeline CLI. Phase 2 wires `import` (ingest). Later phases append
 * prepare-gads / export and grow `import` into the full pipeline (§2) via a runner.
 */
class KeyforgeController extends Controller
{
    /** Tenant scope; tenant UI is deferred (§13) so it defaults to the seeded project. */
    public int $projectId = 1;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'import' ? ['projectId'] : []);
    }

    /**
     * Ingest a keyword source file into kf_keyword (idempotent).
     *
     * @param string $file path to a known sample CSV (source_type inferred from the file name)
     */
    public function actionImport(string $file): int
    {
        $path = Yii::getAlias($file);
        if (!is_file($path)) {
            $this->stderr("File not found: {$file}\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            $this->stderr("Only .csv sources are wired up so far (got '.{$extension}')\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $source = CsvSourceCatalog::fromFile($path);
        } catch (InvalidArgumentException $exception) {
            $this->stderr($exception->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $stage = new IngestStage(
            $source,
            basename($path),
            Yii::$app->db,
            new ImportHashCalculator(),
            new KeywordNormalizer()
        );
        $context = $stage->run(new PipelineContext($this->projectId));
        $stats = $context->stageStats()['ingest'];

        $this->stdout(
            "Imported {$source->sourceType()} from " . basename($path)
            . ": {$stats['out']} new / {$stats['in']} rows (batch #{$context->importBatchId})\n",
            Console::FG_GREEN
        );

        return ExitCode::OK;
    }
}
