<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\LanguageDetector;
use yii\db\Connection;

/**
 * Language detection (§2.3): detect each active keyword's language by its TEXT
 * (don't trust the source). A confident detection overwrites detected_language;
 * an undetermined one (short/translit/ambiguous) keeps the source-seeded value as
 * the fallback (§11). Validation against language->url is deferred to grouping
 * (Phase 4), where the map is actually used.
 */
final class LanguageDetectStage implements PipelineStage
{
    private Connection $db;
    private LanguageDetector $detector;

    public function __construct(Connection $db, LanguageDetector $detector)
    {
        $this->db = $db;
        $this->detector = $detector;
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->db->createCommand(
            'SELECT id, normalized_keyword FROM kf_keyword WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        foreach ($activeKeywords as $keyword) {
            $detected = $this->detector->detect((string) $keyword['normalized_keyword']);
            if ($detected === null) {
                continue; // keep source_language fallback
            }
            $this->db->createCommand()
                ->update('kf_keyword', ['detected_language' => $detected], ['id' => $keyword['id']])
                ->execute();
        }

        $received = count($activeKeywords);
        $context->recordStage('language_detect', $received, $received);

        return $context;
    }
}
