<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Input for ad-copy generation (§2.9): the group's language, final URL, the
 * keywords to theme the ad on, and an optional brand headline to pin to position 1.
 * Plain DTO.
 */
final readonly class AdCopyRequest
{
    /** @param string[] $keywords */
    public function __construct(
        public string $language,
        public string $targetUrl,
        public array $keywords,
        public ?string $brandHeadline = null,
    ) {
    }
}
