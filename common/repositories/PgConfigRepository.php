<?php

declare(strict_types=1);

namespace common\repositories;

use yii\db\Connection;

/** PostgreSQL adapter for ConfigRepositoryInterface (§15). */
final class PgConfigRepository implements ConfigRepositoryInterface
{
    public function __construct(private Connection $db)
    {
    }

    public function brandTerms(int $projectId): array
    {
        return $this->db->createCommand(
            'SELECT term FROM kf_config_brand_term WHERE project_id = :p', [':p' => $projectId]
        )->queryColumn();
    }

    public function forbiddenTerms(int $projectId): array
    {
        return $this->db->createCommand(
            'SELECT term FROM kf_config_forbidden_term WHERE project_id = :p', [':p' => $projectId]
        )->queryColumn();
    }

    public function volumeThreshold(int $projectId, string $language): ?array
    {
        $row = $this->db->createCommand(
            'SELECT percentile, min_search_volume FROM kf_config_volume_threshold
             WHERE project_id = :p AND language = :lang',
            [':p' => $projectId, ':lang' => $language]
        )->queryOne();

        if ($row === false) {
            return null;
        }

        return [
            'percentile' => (float) $row['percentile'],
            'minSearchVolume' => $row['min_search_volume'] === null ? null : (float) $row['min_search_volume'],
        ];
    }

    public function languageUrl(int $projectId, string $language): ?string
    {
        $url = $this->db->createCommand(
            'SELECT target_url FROM kf_config_language_url_map WHERE project_id = :p AND language = :lang',
            [':p' => $projectId, ':lang' => $language]
        )->queryScalar();

        return $url === false ? null : (string) $url;
    }

    public function projectName(int $projectId): ?string
    {
        $name = $this->db->createCommand(
            'SELECT name FROM kf_project WHERE id = :p', [':p' => $projectId]
        )->queryScalar();

        return $name === false ? null : (string) $name;
    }
}
