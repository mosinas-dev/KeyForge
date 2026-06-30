<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\pipeline\KeywordStatus;
use common\pipeline\PipelineContext;
use common\pipeline\PipelineStage;
use common\services\JunkClassifier;
use yii\db\Connection;

/**
 * Junk filter (§2.2): scans active keywords, and for each one the classifier flags
 * as junk sets status='junk' AND records it in kf_negative_keyword (NOT deleted —
 * the marketer gets minus-words for free). Idempotent: only status='new' rows are
 * processed, and negatives are inserted ON CONFLICT DO NOTHING (§11).
 */
final class JunkFilterStage implements PipelineStage
{
    public function __construct(
        private Connection $db,
        private JunkClassifier $classifier,
    ) {
    }

    public function run(PipelineContext $context): PipelineContext
    {
        $activeKeywords = $this->db->createCommand(
            'SELECT id, normalized_keyword, detected_language FROM kf_keyword
             WHERE project_id = :p AND status = :s',
            [':p' => $context->projectId, ':s' => KeywordStatus::NEW]
        )->queryAll();

        $movedToNegatives = 0;
        foreach ($activeKeywords as $keyword) {
            $reason = $this->classifier->classify((string) $keyword['normalized_keyword']);
            if ($reason === null) {
                continue;
            }

            $this->db->createCommand()
                ->update('kf_keyword', ['status' => KeywordStatus::JUNK], ['id' => $keyword['id']])
                ->execute();

            $this->db->createCommand(
                'INSERT INTO kf_negative_keyword (project_id, keyword_text, reason, language)
                 VALUES (:p, :text, :reason, :lang)
                 ON CONFLICT (project_id, keyword_text) DO NOTHING',
                [
                    ':p' => $context->projectId,
                    ':text' => $keyword['normalized_keyword'],
                    ':reason' => $reason,
                    ':lang' => $keyword['detected_language'],
                ]
            )->execute();
            $movedToNegatives++;
        }

        $received = count($activeKeywords);
        $context->recordStage('junk_filter', $received, $received - $movedToNegatives);

        return $context;
    }
}
