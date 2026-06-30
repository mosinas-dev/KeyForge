<?php

declare(strict_types=1);

namespace common\services;

use common\adgen\AdCopyGenerator;
use common\adgen\RsaLengthValidator;
use common\export\CampaignExporter;
use common\export\ExportResult;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineRunner;
use common\pipeline\stages\AdGenerationStage;
use common\pipeline\stages\BrandClassifyStage;
use common\pipeline\stages\ExportStage;
use common\pipeline\stages\FuzzyDedupStage;
use common\pipeline\stages\GadsPrepStage;
use common\pipeline\stages\IngestStage;
use common\pipeline\stages\IntentClassifyStage;
use common\pipeline\stages\JunkFilterStage;
use common\pipeline\stages\LanguageDetectStage;
use common\pipeline\stages\VolumeFilterStage;
use common\repositories\AdGroupRepositoryInterface;
use common\repositories\ConfigRepositoryInterface;
use common\repositories\ImportBatchRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\repositories\NegativeKeywordRepositoryInterface;
use common\sources\KeywordSourceProvider;

/**
 * Application service that orchestrates the keyword pipeline (§2), shared by the
 * console commands and the backend admin so both stay thin (§15.5/15.6) and the
 * stage assembly lives in one place (DRY). Single responsibility: run the pipeline.
 * All stage dependencies are injected; this service only composes and runs them.
 */
final class KeywordPipelineService
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private ConfigRepositoryInterface $config,
        private AdGroupRepositoryInterface $adGroups,
        private NegativeKeywordRepositoryInterface $negatives,
        private ImportBatchRepositoryInterface $batches,
        private KeywordNormalizer $keywordNormalizer,
        private ImportHashCalculator $importHashCalculator,
        private JunkClassifier $junkClassifier,
        private TermMatcher $termMatcher,
        private LanguageDetector $languageDetector,
        private IntentClassifier $intentClassifier,
        private AdCopyGenerator $adCopyGenerator,
        private RsaLengthValidator $rsaLengthValidator,
        private CampaignExporter $campaignExporter,
    ) {
    }

    /** Ingest a source file, then run the §2.2–2.6 cleaning pipeline. Idempotent. */
    public function importSource(int $projectId, KeywordSourceProvider $source, string $fileName): PipelineContext
    {
        $ingest = new IngestStage(
            $source,
            $fileName,
            $this->keywords,
            $this->batches,
            $this->importHashCalculator,
            $this->keywordNormalizer
        );
        $context = $ingest->run(new PipelineContext($projectId));

        return (new PipelineRunner($this->cleaningStages()))->run($context);
    }

    /** GAds-prep (§2.7–2.8) + RSA generation (§2.9). */
    public function prepareCampaigns(int $projectId): PipelineContext
    {
        $runner = new PipelineRunner([
            new GadsPrepStage($this->keywords, $this->config, $this->adGroups, $this->termMatcher),
            new AdGenerationStage($this->keywords, $this->adGroups, $this->config, $this->adCopyGenerator, $this->rsaLengthValidator, $this->languageDetector),
        ]);

        return $runner->run(new PipelineContext($projectId));
    }

    /** Build the Google Ads Editor files (§2.10). */
    public function export(int $projectId): ExportResult
    {
        return (new ExportStage($this->adGroups, $this->keywords, $this->negatives, $this->campaignExporter))
            ->export($projectId);
    }

    /** @return \common\pipeline\PipelineStage[] the §2.2–2.6 cleaning stages in order */
    private function cleaningStages(): array
    {
        return [
            new JunkFilterStage($this->keywords, $this->negatives, $this->junkClassifier),
            new BrandClassifyStage($this->keywords, $this->config, $this->termMatcher),
            new LanguageDetectStage($this->keywords, $this->languageDetector),
            new IntentClassifyStage($this->keywords, $this->intentClassifier),
            new FuzzyDedupStage($this->keywords, $this->keywordNormalizer),
            new VolumeFilterStage($this->keywords, $this->config),
        ];
    }
}
