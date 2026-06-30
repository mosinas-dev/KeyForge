<?php

declare(strict_types=1);

namespace common\repositories;

/**
 * Read access to the kf_config_* rule tables (§1: rules as DATA, §15.12). Brand/
 * forbidden terms, per-language volume thresholds, language->url map.
 */
interface ConfigRepositoryInterface
{
    /** @return string[] */
    public function brandTerms(int $projectId): array;

    /** @return string[] */
    public function forbiddenTerms(int $projectId): array;

    /** @return array{percentile:float,minSearchVolume:?float}|null */
    public function volumeThreshold(int $projectId, string $language): ?array;

    /** Target URL configured for a language, or null if the language is not mapped. */
    public function languageUrl(int $projectId, string $language): ?string;

    /** Project name (used as the pinned brand headline in RSA, §2.9), or null. */
    public function projectName(int $projectId): ?string;
}
