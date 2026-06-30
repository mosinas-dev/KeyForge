<?php

declare(strict_types=1);

namespace common\repositories;

/**
 * Repository for the ad-group aggregate (kf_ad_group + its kf_responsive_search_ad),
 * §15.8/15.16. STAG groups are keyed by (project_id, intent_class, language).
 */
interface AdGroupRepositoryInterface
{
    /** Create or refresh the STAG group for (intent, language) — idempotent (upsert). */
    public function upsertGroup(int $projectId, string $groupName, string $intentClass, string $language, string $targetUrl): void;

    /** @return array<int,array{id:int,group_name:string,intent_class:string,language:string,target_url:string}> */
    public function allGroups(int $projectId): array;

    /**
     * Replace the single RSA of an ad group (delete + insert -> idempotent).
     * @param array<int,array{text:string,pin:?int}> $headlines
     * @param array<int,array{text:string,pin:?int}> $descriptions
     */
    public function replaceRsa(int $adGroupId, array $headlines, array $descriptions, string $validationStatus): void;

    /**
     * The valid RSA copy of an ad group, decoded, or null if none is valid.
     * @return array{headlines:array<int,array{text:string,pin:?int}>,descriptions:array<int,array{text:string,pin:?int}>}|null
     */
    public function findValidRsaCopy(int $adGroupId): ?array;
}
