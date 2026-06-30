<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use yii\db\Connection;

/**
 * Adaptive volume cutoff (§2.6): per-language, not global, so small languages
 * aren't unfairly cut. For each language with a kf_config_volume_threshold (rules
 * as DATA), the threshold is the configured percentile of that language's volumes
 * (optionally floored by min_search_volume); keywords strictly below it become
 * status='low_volume'.
 *
 * Edge cases (§11): a single-row language keeps its only keyword (percentile = that
 * value, nothing is strictly below); all-zeros keeps all; a language with no
 * threshold config is left untouched. Null volumes are never filtered (unknown).
 */
final class VolumeFilterStage implements PipelineStage
{
    public function __construct(private Connection $db)
    {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $thresholdConfig = $this->loadThresholdConfig($context->projectId);

        $languages = $this->db->createCommand(
            'SELECT DISTINCT detected_language FROM kf_keyword
             WHERE project_id = :p AND status = :s AND detected_language IS NOT NULL',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryColumn();

        $filtered = 0;
        foreach ($languages as $language) {
            if (!isset($thresholdConfig[$language])) {
                continue; // no cutoff configured for this language -> keep all
            }
            $threshold = $this->percentileThreshold($context->projectId, $language, (float) $thresholdConfig[$language]['percentile']);
            if ($threshold === null) {
                continue; // no measurable (non-null) volumes
            }
            $minVolume = $thresholdConfig[$language]['min_search_volume'];
            if ($minVolume !== null) {
                $threshold = max($threshold, (float) $minVolume);
            }

            $filtered += $this->db->createCommand(
                'UPDATE kf_keyword SET status = :low
                 WHERE project_id = :p AND status = :new AND detected_language = :lang
                   AND search_volume IS NOT NULL AND search_volume < :threshold',
                [
                    ':low' => KeywordStatus::LOW_VOLUME,
                    ':p' => $context->projectId,
                    ':new' => KeywordStatus::NEW,
                    ':lang' => $language,
                    ':threshold' => $threshold,
                ]
            )->execute();
        }

        $received = (int) $this->db->createCommand(
            'SELECT COUNT(*) FROM kf_keyword WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryScalar() + $filtered;
        $context->recordStage('volume_filter', $received, $received - $filtered);

        return $context;
    }

    /** @return array<string,array{percentile:string,min_search_volume:?string}> */
    private function loadThresholdConfig(int $projectId): array
    {
        $rows = $this->db->createCommand(
            'SELECT language, percentile, min_search_volume FROM kf_config_volume_threshold WHERE project_id = :p',
            [':p' => $projectId]
        )->queryAll();

        $config = [];
        foreach ($rows as $row) {
            $config[$row['language']] = [
                'percentile' => $row['percentile'],
                'min_search_volume' => $row['min_search_volume'],
            ];
        }

        return $config;
    }

    private function percentileThreshold(int $projectId, string $language, float $percentile): ?float
    {
        $value = $this->db->createCommand(
            'SELECT percentile_cont(:pct) WITHIN GROUP (ORDER BY search_volume)
             FROM kf_keyword
             WHERE project_id = :p AND status = :s AND detected_language = :lang AND search_volume IS NOT NULL',
            [':pct' => $percentile, ':p' => $projectId, ':s' => KeywordStatus::NEW, ':lang' => $language]
        )->queryScalar();

        return $value === null || $value === false ? null : (float) $value;
    }
}
