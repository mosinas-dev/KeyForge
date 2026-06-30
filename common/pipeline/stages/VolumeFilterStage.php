<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\repositories\ConfigRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;

/**
 * Adaptive volume cutoff (§2.6): per-language, not global, so small languages
 * aren't unfairly cut. For each language with a configured threshold, the cutoff is
 * the configured percentile of that language's volumes (optionally floored by
 * min_search_volume); keywords strictly below it become status='low_volume'.
 *
 * Edge cases (§11): a single-row language keeps its only keyword (percentile = that
 * value, nothing is strictly below); all-zeros keeps all; an unconfigured language
 * is left untouched; null volumes are never filtered.
 */
final class VolumeFilterStage implements PipelineStage
{
    public function __construct(
        private KeywordRepositoryInterface $keywords,
        private ConfigRepositoryInterface $config,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $projectId = $context->projectId;

        $filtered = 0;
        foreach ($this->keywords->activeLanguages($projectId) as $language) {
            $threshold = $this->config->volumeThreshold($projectId, $language);
            if ($threshold === null) {
                continue; // no cutoff configured for this language -> keep all
            }
            $percentileValue = $this->keywords->percentileVolume($projectId, $language, $threshold['percentile']);
            if ($percentileValue === null) {
                continue; // no measurable (non-null) volumes
            }
            $effectiveThreshold = $threshold['minSearchVolume'] !== null
                ? max($percentileValue, $threshold['minSearchVolume'])
                : $percentileValue;

            $filtered += $this->keywords->markLowVolumeBelow($projectId, $language, $effectiveThreshold);
        }

        $remaining = count($this->keywords->findActive($projectId));
        $context->recordStage('volume_filter', $remaining + $filtered, $remaining);

        return $context;
    }
}
