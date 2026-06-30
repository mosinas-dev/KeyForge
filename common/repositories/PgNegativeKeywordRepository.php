<?php

declare(strict_types=1);

namespace common\repositories;

use yii\db\Connection;

/** PostgreSQL adapter for NegativeKeywordRepositoryInterface (§15). */
final class PgNegativeKeywordRepository implements NegativeKeywordRepositoryInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function addIgnoringDuplicate(int $projectId, string $keywordText, string $reason, ?string $language): void
    {
        $this->db->createCommand(
            'INSERT INTO kf_negative_keyword (project_id, keyword_text, reason, language)
             VALUES (:p, :text, :reason, :lang)
             ON CONFLICT (project_id, keyword_text) DO NOTHING',
            [':p' => $projectId, ':text' => $keywordText, ':reason' => $reason, ':lang' => $language]
        )->execute();
    }

    public function allTexts(int $projectId): array
    {
        return $this->db->createCommand(
            'SELECT keyword_text FROM kf_negative_keyword WHERE project_id = :p ORDER BY keyword_text',
            [':p' => $projectId]
        )->queryColumn();
    }
}
