<?php

declare(strict_types=1);

namespace common\pipeline;

/**
 * Lifecycle values for kf_keyword.status — one place so stages agree (DRY).
 * Backed string enum (§14): the backing value is what's stored in the column.
 * 'new' = active/eligible; the rest are terminal exclusions set by the pipeline.
 */
enum KeywordStatus: string
{
    case New = 'new';
    case Junk = 'junk';          // routed to kf_negative_keyword (§2.2)
    case Merged = 'merged';      // duplicate folded into a canon (§2.5)
    case LowVolume = 'low_volume'; // below the per-language threshold (§2.6)
    case Used = 'used';          // already in our Google Ads (google_ads source) (§2.8)
}
