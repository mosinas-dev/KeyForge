<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\export\CampaignExporter;
use common\pipeline\KeywordStatus;
use yii\db\Connection;

/**
 * Export (§2.10): reads the prepared project (ad groups + their eligible keywords +
 * valid RSA) and the negative keywords, and hands them to a CampaignExporter, which
 * returns the file map (name => content). Writing to disk is the caller's job.
 *
 * Terminal step with a distinct shape (produces files), so it isn't a PipelineStage.
 */
final class ExportStage
{
    private const MATCH_TYPE = 'Phrase';

    public function __construct(
        private Connection $db,
        private CampaignExporter $exporter,
    ) {
    }

    /** @return array<string,string> file name => content */
    public function export(int $projectId): array
    {
        return $this->exporter->export($this->buildGroups($projectId), $this->negativeKeywords($projectId));
    }

    /** @return array<int,array> */
    private function buildGroups(int $projectId): array
    {
        $adGroups = $this->db->createCommand(
            'SELECT id, group_name, intent_class, language, target_url FROM kf_ad_group
             WHERE project_id = :p ORDER BY language, intent_class',
            [':p' => $projectId]
        )->queryAll();

        $groups = [];
        foreach ($adGroups as $adGroup) {
            [$headlines, $descriptions] = $this->adCopy((int) $adGroup['id']);
            $groups[] = [
                'campaign' => 'SP_' . strtoupper((string) $adGroup['language']),
                'adGroup' => (string) $adGroup['group_name'],
                'finalUrl' => (string) $adGroup['target_url'],
                'matchType' => self::MATCH_TYPE,
                'keywords' => $this->eligibleKeywords($projectId, $adGroup),
                'headlines' => $headlines,
                'descriptions' => $descriptions,
            ];
        }

        return $groups;
    }

    /** @return string[] */
    private function eligibleKeywords(int $projectId, array $adGroup): array
    {
        return $this->db->createCommand(
            'SELECT normalized_keyword FROM kf_keyword
             WHERE project_id = :p AND status = :s AND intent_class = :i AND detected_language = :l
               AND is_brand = false AND is_forbidden = false
             ORDER BY search_volume DESC NULLS LAST, id ASC',
            [':p' => $projectId, ':s' => KeywordStatus::NEW, ':i' => $adGroup['intent_class'], ':l' => $adGroup['language']]
        )->queryColumn();
    }

    /** @return array{0:string[],1:string[]} [headline texts, description texts] from the group's valid RSA */
    private function adCopy(int $adGroupId): array
    {
        $rsa = $this->db->createCommand(
            "SELECT headlines, descriptions FROM kf_responsive_search_ad
             WHERE ad_group_id = :g AND validation_status = 'valid' LIMIT 1",
            [':g' => $adGroupId]
        )->queryOne();

        if ($rsa === false) {
            return [[], []];
        }

        return [$this->texts((string) $rsa['headlines']), $this->texts((string) $rsa['descriptions'])];
    }

    /** @return string[] */
    private function texts(string $json): array
    {
        $items = json_decode($json, true) ?: [];

        return array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), $items);
    }

    /** @return string[] */
    private function negativeKeywords(int $projectId): array
    {
        return $this->db->createCommand(
            'SELECT keyword_text FROM kf_negative_keyword WHERE project_id = :p ORDER BY keyword_text',
            [':p' => $projectId]
        )->queryColumn();
    }
}
