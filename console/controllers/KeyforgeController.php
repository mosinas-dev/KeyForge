<?php

declare(strict_types=1);

namespace console\controllers;

use common\adgen\AdCopyGenerator;
use common\adgen\RsaLengthValidator;
use common\export\CampaignExporter;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineRunner;
use common\pipeline\stages\AdGenerationStage;
use common\pipeline\stages\BrandClassifyStage;
use common\pipeline\stages\FuzzyDedupStage;
use common\pipeline\stages\GadsPrepStage;
use common\pipeline\stages\IngestStage;
use common\pipeline\stages\IntentClassifyStage;
use common\pipeline\stages\JunkFilterStage;
use common\pipeline\stages\LanguageDetectStage;
use common\pipeline\stages\VolumeFilterStage;
use common\services\ImportHashCalculator;
use common\services\IntentClassifier;
use common\services\JunkClassifier;
use common\services\KeywordNormalizer;
use common\services\LanguageDetector;
use common\services\TermMatcher;
use common\sources\CsvSourceCatalog;
use InvalidArgumentException;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * KeyForge pipeline CLI. `import` runs ingest + the cleaning pipeline (§2.1–2.6),
 * `prepare-gads` runs GAds-prep + RSA generation (§2.7–2.9). The cleaning pass is
 * idempotent and incremental — re-running it reconsiders all active keywords — so
 * importing several files and re-cleaning each time still dedups across files.
 *
 * Dependencies (stateless services + ports) are injected by the DI container, not
 * `new`ed here (DIP, §12); the controller is the composition root that assembles
 * stages from them. Port bindings live in common/config/main.php.
 */
final class KeyforgeController extends Controller
{
    /** Tenant scope; tenant UI is deferred (§13) so it defaults to the seeded project. */
    public int $projectId = 1;

    /** Stage funnel order printed after a run (§2.2–2.6). */
    private const CLEANING_STAGE_ORDER = [
        'junk_filter', 'brand_classify', 'language_detect', 'intent_classify', 'fuzzy_dedup', 'volume_filter',
    ];

    public function __construct(
        $id,
        $module,
        private KeywordNormalizer $keywordNormalizer,
        private ImportHashCalculator $importHashCalculator,
        private JunkClassifier $junkClassifier,
        private TermMatcher $termMatcher,
        private LanguageDetector $languageDetector,
        private IntentClassifier $intentClassifier,
        private AdCopyGenerator $adCopyGenerator,
        private RsaLengthValidator $rsaLengthValidator,
        private CampaignExporter $campaignExporter,
        array $config = []
    ) {
        parent::__construct($id, $module, $config);
    }

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), in_array($actionID, ['import', 'prepare-gads'], true) ? ['projectId'] : []);
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
            $this->importHashCalculator,
            $this->keywordNormalizer
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

    /**
     * GAds-prep + RSA generation (§2.7–2.9): mark used/forbidden/opportunity, build
     * STAG ad groups, then generate a validated RSA per group. Run after importing
     * all source files.
     */
    public function actionPrepareGads(): int
    {
        $db = Yii::$app->db;
        $runner = new PipelineRunner([
            new GadsPrepStage($db, $this->termMatcher),
            new AdGenerationStage($db, $this->adCopyGenerator, $this->rsaLengthValidator, $this->languageDetector),
        ]);
        $context = $runner->run(new PipelineContext($this->projectId));

        $prep = $context->stageStats()['gads_prep'];
        $ads = $context->stageStats()['ad_generation'];
        $this->stdout(
            "GAds-prep: {$prep['out']} ad group(s) from {$prep['in']} eligible keyword(s)\n"
            . "RSA gen: {$ads['out']}/{$ads['in']} group(s) got a valid ad\n",
            Console::FG_GREEN
        );

        return ExitCode::OK;
    }

    /** @return \common\pipeline\PipelineStage[] the §2.2–2.6 cleaning stages in order */
    private function cleaningStages(): array
    {
        $db = Yii::$app->db;

        return [
            new JunkFilterStage($db, $this->junkClassifier),
            new BrandClassifyStage($db, $this->termMatcher),
            new LanguageDetectStage($db, $this->languageDetector),
            new IntentClassifyStage($db, $this->intentClassifier),
            new FuzzyDedupStage($db, $this->keywordNormalizer),
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
