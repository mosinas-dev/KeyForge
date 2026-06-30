<?php

declare(strict_types=1);

namespace console\controllers;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineRunner;
use common\pipeline\stages\BrandClassifyStage;
use common\pipeline\stages\FuzzyDedupStage;
use common\pipeline\stages\IngestStage;
use common\pipeline\stages\IntentClassifyStage;
use common\pipeline\stages\JunkFilterStage;
use common\pipeline\stages\LanguageDetectStage;
use common\pipeline\stages\VolumeFilterStage;
use common\services\BrandMatcher;
use common\services\ImportHashCalculator;
use common\services\IntentClassifier;
use common\services\JunkClassifier;
use common\services\KeywordNormalizer;
use common\services\LanguageDetector;
use common\sources\CsvSourceCatalog;
use InvalidArgumentException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * KeyForge pipeline CLI. `import` runs ingest + the cleaning pipeline (§2.1–2.6).
 * Later phases append prepare-gads / export. The cleaning pass is idempotent and
 * incremental — re-running it reconsiders all active (status='new') keywords —
 * so importing several files and re-cleaning each time still dedups across files.
 */
class KeyforgeController extends Controller
{
    /** Tenant scope; tenant UI is deferred (§13) so it defaults to the seeded project. */
    public int $projectId = 1;

    /** Stage funnel order printed after a run (§2.2–2.6). */
    private const CLEANING_STAGE_ORDER = [
        'junk_filter', 'brand_classify', 'language_detect', 'intent_classify', 'fuzzy_dedup', 'volume_filter',
    ];

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'import' ? ['projectId'] : []);
    }

    /**
     * Ingest a keyword source file into kf_keyword, then run the cleaning pipeline
     * (§2.1–2.6). Idempotent.
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

        $ingest = new IngestStage(
            $source,
            basename($path),
            Yii::$app->db,
            new ImportHashCalculator(),
            new KeywordNormalizer()
        );
        $context = $ingest->run(new PipelineContext($this->projectId));
        $ingestStats = $context->stageStats()['ingest'];

        $this->stdout(
            "Imported {$source->sourceType()} from " . basename($path)
            . ": {$ingestStats['out']} new / {$ingestStats['in']} rows (batch #{$context->importBatchId})\n",
            Console::FG_GREEN
        );

        $context = (new PipelineRunner($this->cleaningStages()))->run($context);
        $this->printCleaningFunnel($context);

        return ExitCode::OK;
    }

    /** @return \common\pipeline\PipelineStage[] the §2.2–2.6 cleaning stages in order */
    private function cleaningStages(): array
    {
        $db = Yii::$app->db;

        return [
            new JunkFilterStage($db, new JunkClassifier()),
            new BrandClassifyStage($db, new BrandMatcher()),
            new LanguageDetectStage($db, new LanguageDetector()),
            new IntentClassifyStage($db, new IntentClassifier()),
            new FuzzyDedupStage($db, new KeywordNormalizer()),
            new VolumeFilterStage($db),
        ];
    }

    private function printCleaningFunnel(PipelineContext $context): void
    {
        $stats = $context->stageStats();
        foreach (self::CLEANING_STAGE_ORDER as $stageName) {
            if (!isset($stats[$stageName])) {
                continue;
            }
            $this->stdout("  {$stageName}: {$stats[$stageName]['out']}/{$stats[$stageName]['in']} active\n");
        }
    }
}
