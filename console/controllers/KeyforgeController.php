<?php

declare(strict_types=1);

namespace console\controllers;

use common\services\KeywordPipelineService;
use common\sources\CsvSourceCatalog;
use InvalidArgumentException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\FileHelper;

/**
 * KeyForge pipeline CLI. `import` runs ingest + cleaning (§2.1–2.6), `prepare-gads`
 * runs GAds-prep + RSA (§2.7–2.9), `export` writes the Google Ads Editor files (§2.10).
 *
 * Composition root (§15.6): the pipeline is orchestrated by the injected
 * KeywordPipelineService; the controller validates input, calls the service, and
 * reports. Runtime params (file path, output dir) are passed explicitly.
 */
final class KeyforgeController extends Controller
{
    /** Tenant scope; tenant UI is deferred (§13) so it defaults to the seeded project. */
    public int $projectId = 1;

    /** Where `export` writes the Google Ads Editor files. */
    public string $outputDir = '@runtime/export';

    private const CLEANING_STAGE_ORDER = [
        'junk_filter', 'brand_classify', 'language_detect', 'intent_classify', 'fuzzy_dedup', 'volume_filter',
    ];

    public function __construct(
        $id,
        $module,
        private KeywordPipelineService $pipeline,
        array $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        if (in_array($actionID, ['import', 'prepare-gads', 'export'], true)) {
            $options[] = 'projectId';
        }
        if ($actionID === 'export') {
            $options[] = 'outputDir';
        }

        return $options;
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
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            $this->stderr("Only .csv sources are wired up so far\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $source = CsvSourceCatalog::fromFile($path);
        } catch (InvalidArgumentException $exception) {
            $this->stderr($exception->getMessage() . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $context = $this->pipeline->importSource($this->projectId, $source, basename($path));
        $ingest = $context->stageStats()['ingest'];
        $this->stdout(
            "Imported {$source->sourceType()} from " . basename($path)
            . ": {$ingest['out']} new / {$ingest['in']} rows (batch #{$context->importBatchId})\n",
            Console::FG_GREEN
        );
        foreach (self::CLEANING_STAGE_ORDER as $stageName) {
            $stats = $context->stageStats()[$stageName] ?? null;
            if ($stats !== null) {
                $this->stdout("  {$stageName}: {$stats['out']}/{$stats['in']} active\n");
            }
        }

        return ExitCode::OK;
    }

    /** GAds-prep + RSA generation (§2.7–2.9). Run after importing all sources. */
    public function actionPrepareGads(): int
    {
        $context = $this->pipeline->prepareCampaigns($this->projectId);
        $prep = $context->stageStats()['gads_prep'];
        $ads = $context->stageStats()['ad_generation'];
        $this->stdout(
            "GAds-prep: {$prep['out']} ad group(s) from {$prep['in']} eligible keyword(s)\n"
            . "RSA gen: {$ads['out']}/{$ads['in']} group(s) got a valid ad\n",
            Console::FG_GREEN
        );

        return ExitCode::OK;
    }

    /** Export (§2.10): write the Google Ads Editor files to outputDir. */
    public function actionExport(): int
    {
        $result = $this->pipeline->export($this->projectId);

        $dir = Yii::getAlias($this->outputDir);
        FileHelper::createDirectory($dir);
        foreach ($result->files as $name => $content) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $content);
            $this->stdout("Wrote {$dir}/{$name}\n", Console::FG_GREEN);
        }
        $this->stdout(
            "Exported {$result->adGroupCount} ad group(s), {$result->negativeKeywordCount} negative(s)\n",
            Console::FG_GREEN
        );

        return ExitCode::OK;
    }
}
