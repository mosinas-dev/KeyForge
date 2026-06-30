<?php

declare(strict_types=1);

namespace common\adgen;

/**
 * Input for ad-copy generation (§2.9): the group's language, final URL, the
 * keywords to theme the ad on, and an optional brand headline to pin to position 1.
 * Plain DTO.
 */
final class AdCopyRequest
{
    public string $language;
    public string $targetUrl;
    /** @var string[] */
    public array $keywords;
    public ?string $brandHeadline;

    /** @param string[] $keywords */
    public function __construct(string $language, string $targetUrl, array $keywords, ?string $brandHeadline = null)
    {
        $this->language = $language;
        $this->targetUrl = $targetUrl;
        $this->keywords = $keywords;
        $this->brandHeadline = $brandHeadline;
    }
}
