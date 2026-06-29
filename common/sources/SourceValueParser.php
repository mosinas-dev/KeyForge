<?php

declare(strict_types=1);

namespace common\sources;

/**
 * Shared raw-value parsing for keyword sources (DRY, §12 — one place for the
 * volume rule and string cleaning, used by CsvSource/JsonSource).
 */
trait SourceValueParser
{
    /** Trim; empty -> null. */
    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Non-numeric, empty, or negative volume -> null (§11). */
    private function parseVolume(mixed $value): ?int
    {
        $clean = $this->cleanString($value);
        if ($clean === null) {
            return null;
        }
        $int = filter_var($clean, FILTER_VALIDATE_INT);

        return ($int === false || $int < 0) ? null : $int;
    }
}
