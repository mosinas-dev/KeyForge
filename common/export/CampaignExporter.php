<?php

declare(strict_types=1);

namespace common\export;

/**
 * Port for exporting prepared campaigns (§2.10, ISP/DIP). The stage depends on this
 * interface; GoogleAdsEditorExporter is the concrete adapter (bound in the DI
 * container). A future exporter (e.g. Google Ads API) is a swap behind this method.
 */
interface CampaignExporter
{
    /**
     * @param array<int,array{campaign:string,adGroup:string,finalUrl:string,matchType:string,keywords:string[],headlines:string[],descriptions:string[]}> $groups
     * @param string[] $negativeKeywords
     * @return array<string,string> file name => file content
     */
    public function export(array $groups, array $negativeKeywords): array;
}
