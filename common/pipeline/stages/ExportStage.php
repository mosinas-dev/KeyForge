<?php

declare(strict_types=1);

namespace common\pipeline\stages;

use common\export\CampaignExporter;
use common\export\ExportResult;
use common\repositories\AdGroupRepositoryInterface;
use common\repositories\KeywordRepositoryInterface;
use common\repositories\NegativeKeywordRepositoryInterface;

/**
 * Export (§2.10): reads the prepared project (ad groups + their eligible keywords +
 * valid RSA) and the negative keywords via repositories, and hands them to a
 * CampaignExporter, which returns the file map (name => content). Writing to disk is
 * the caller's job. Terminal step (produces files), so not a PipelineStage.
 */
final class ExportStage
{
    private const MATCH_TYPE = 'Phrase';

    public function __construct(
        private AdGroupRepositoryInterface $adGroups,
        private KeywordRepositoryInterface $keywords,
        private NegativeKeywordRepositoryInterface $negatives,
        private CampaignExporter $exporter,
    ) {
    }

    public function export(int $projectId): ExportResult
    {
        $groups = $this->buildGroups($projectId);
        $negatives = $this->negatives->allTexts($projectId);
        $files = $this->exporter->export($groups, $negatives);

        return new ExportResult($files, count($groups), count($negatives));
    }

    /** @return array<int,array> */
    private function buildGroups(int $projectId): array
    {
        $groups = [];
        foreach ($this->adGroups->allGroups($projectId) as $adGroup) {
            $copy = $this->adGroups->findValidRsaCopy($adGroup['id']);
            $groups[] = [
                'campaign' => 'SP_' . strtoupper($adGroup['language']),
                'adGroup' => $adGroup['group_name'],
                'finalUrl' => $adGroup['target_url'],
                'matchType' => self::MATCH_TYPE,
                'keywords' => $this->keywords->eligibleKeywords($projectId, $adGroup['intent_class'], $adGroup['language']),
                'headlines' => $copy === null ? [] : array_column($copy['headlines'], 'text'),
                'descriptions' => $copy === null ? [] : array_column($copy['descriptions'], 'text'),
            ];
        }

        return $groups;
    }
}
