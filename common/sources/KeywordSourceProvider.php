<?php

declare(strict_types=1);

namespace common\sources;

/**
 * Port for a keyword source (CSV/JSON now; API later — §13). Narrow by design
 * (ISP/DIP, §12): the pipeline depends on this interface, not concrete sources.
 * New source = new class implementing this, no edits to existing ones (OCP).
 *
 * rows() yields canonical associative arrays with the keys in self::FIELDS;
 * missing/unknown values are null. Raw, pre-normalization data — normalization
 * and language detection happen downstream.
 */
interface KeywordSourceProvider
{
    /** Canonical row field keys produced by rows(). */
    public const FIELDS = [
        'raw_keyword',
        'search_volume',
        'source_country',
        'source_url',
        'source_language',
    ];

    /** Stable source type, e.g. 'ahrefs_paid', 'google_ads' (goes into import_hash). */
    public function sourceType(): string;

    /** sha256 of the raw source bytes — the file_hash component of import_hash (ADR 0006). */
    public function fingerprint(): string;

    /** @return iterable<array{raw_keyword:string,search_volume:?int,source_country:?string,source_url:?string,source_language:?string}> */
    public function rows(): iterable;
}
