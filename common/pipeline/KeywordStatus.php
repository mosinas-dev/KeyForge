<?php

declare(strict_types=1);

namespace common\pipeline;

/**
 * Lifecycle values for kf_keyword.status — one place so stages agree (DRY).
 * 'new' = active/eligible; the rest are terminal exclusions set by stages.
 */
final class KeywordStatus
{
    public const NEW = 'new';
    public const JUNK = 'junk';          // routed to kf_negative_keyword (§2.2)
    public const MERGED = 'merged';      // duplicate folded into a canon (§2.5)
    public const LOW_VOLUME = 'low_volume'; // below the per-language threshold (§2.6)
    public const USED = 'used';          // already in our Google Ads (google_ads source) (§2.8)
}
