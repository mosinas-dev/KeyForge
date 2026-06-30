<?php

declare(strict_types=1);

namespace common\repositories;

use yii\db\Connection;

/** PostgreSQL adapter for AdGroupRepositoryInterface (§15). */
final class PgAdGroupRepository implements AdGroupRepositoryInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function upsertGroup(int $projectId, string $groupName, string $intentClass, string $language, string $targetUrl): void
    {
        $this->db->createCommand(
            'INSERT INTO kf_ad_group (project_id, group_name, intent_class, language, target_url)
             VALUES (:p, :name, :intent, :lang, :url)
             ON CONFLICT (project_id, intent_class, language)
             DO UPDATE SET group_name = EXCLUDED.group_name, target_url = EXCLUDED.target_url',
            [':p' => $projectId, ':name' => $groupName, ':intent' => $intentClass, ':lang' => $language, ':url' => $targetUrl]
        )->execute();
    }

    public function allGroups(int $projectId): array
    {
        $rows = $this->db->createCommand(
            'SELECT id, group_name, intent_class, language, target_url FROM kf_ad_group
             WHERE project_id = :p ORDER BY language, intent_class',
            [':p' => $projectId]
        )->queryAll();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'group_name' => (string) $row['group_name'],
            'intent_class' => (string) $row['intent_class'],
            'language' => (string) $row['language'],
            'target_url' => (string) $row['target_url'],
        ], $rows);
    }

    public function replaceRsa(int $adGroupId, array $headlines, array $descriptions, string $validationStatus): void
    {
        $this->db->createCommand('DELETE FROM kf_responsive_search_ad WHERE ad_group_id = :g', [':g' => $adGroupId])->execute();
        $this->db->createCommand(
            'INSERT INTO kf_responsive_search_ad (ad_group_id, headlines, descriptions, validation_status)
             VALUES (:g, CAST(:h AS jsonb), CAST(:d AS jsonb), :s)',
            [
                ':g' => $adGroupId,
                ':h' => json_encode($headlines, JSON_UNESCAPED_UNICODE),
                ':d' => json_encode($descriptions, JSON_UNESCAPED_UNICODE),
                ':s' => $validationStatus,
            ]
        )->execute();
    }

    public function findValidRsaCopy(int $adGroupId): ?array
    {
        $row = $this->db->createCommand(
            "SELECT headlines, descriptions FROM kf_responsive_search_ad
             WHERE ad_group_id = :g AND validation_status = 'valid' LIMIT 1",
            [':g' => $adGroupId]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return [
            'headlines' => json_decode((string) $row['headlines'], true) ?: [],
            'descriptions' => json_decode((string) $row['descriptions'], true) ?: [],
        ];
    }
}
