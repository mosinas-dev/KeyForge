<?php

declare(strict_types=1);

namespace common\export;

use League\Csv\Writer;

/**
 * Google Ads Editor CSV exporter (§2.10). Produces a campaigns file (one keyword
 * row per keyword + one RSA row per ad group) and a separate negatives file.
 * league/csv handles RFC4180 escaping; output is UTF-8. See docs/gads_export_format.md.
 */
final class GoogleAdsEditorExporter implements CampaignExporter
{
    private const MAX_HEADLINES = 15;
    private const MAX_DESCRIPTIONS = 4;
    private const NEGATIVE_MATCH_TYPE = 'Negative Phrase';

    public function export(array $groups, array $negativeKeywords): array
    {
        return [
            'campaigns.csv' => $this->campaignsCsv($groups),
            'negatives.csv' => $this->negativesCsv($negativeKeywords),
        ];
    }

    /** @param array<int,array> $groups */
    private function campaignsCsv(array $groups): string
    {
        $writer = Writer::fromString();
        $writer->insertOne($this->campaignsHeader());

        foreach ($groups as $group) {
            foreach ($group['keywords'] as $keyword) {
                $writer->insertOne($this->keywordRow($group, (string) $keyword));
            }
            // No ad copy (e.g. RSA generation failed) -> keyword rows only, no empty ad row.
            if ($group['headlines'] !== []) {
                $writer->insertOne($this->adRow($group));
            }
        }

        return $writer->toString();
    }

    /** @param string[] $negativeKeywords */
    private function negativesCsv(array $negativeKeywords): string
    {
        $writer = Writer::fromString();
        $writer->insertOne(['Campaign', 'Keyword', 'Match Type']);
        foreach ($negativeKeywords as $keyword) {
            // Empty Campaign = a shared/account-level negative list.
            $writer->insertOne(['', (string) $keyword, self::NEGATIVE_MATCH_TYPE]);
        }

        return $writer->toString();
    }

    /** @return string[] */
    private function campaignsHeader(): array
    {
        $header = ['Campaign', 'Ad Group', 'Keyword', 'Match Type', 'Final URL'];
        for ($i = 1; $i <= self::MAX_HEADLINES; $i++) {
            $header[] = "Headline {$i}";
        }
        for ($i = 1; $i <= self::MAX_DESCRIPTIONS; $i++) {
            $header[] = "Description {$i}";
        }

        return $header;
    }

    /** @param array<string,mixed> $group @return string[] */
    private function keywordRow(array $group, string $keyword): array
    {
        return array_merge(
            [$group['campaign'], $group['adGroup'], $keyword, $group['matchType'], $group['finalUrl']],
            array_fill(0, self::MAX_HEADLINES + self::MAX_DESCRIPTIONS, '')
        );
    }

    /** @param array<string,mixed> $group @return string[] */
    private function adRow(array $group): array
    {
        $headlines = array_slice(array_pad($group['headlines'], self::MAX_HEADLINES, ''), 0, self::MAX_HEADLINES);
        $descriptions = array_slice(array_pad($group['descriptions'], self::MAX_DESCRIPTIONS, ''), 0, self::MAX_DESCRIPTIONS);

        return array_merge(
            [$group['campaign'], $group['adGroup'], '', '', $group['finalUrl']],
            $headlines,
            $descriptions
        );
    }
}
