<?php

declare(strict_types=1);

namespace common\sources;

use League\Csv\Reader;

/**
 * CSV keyword source (§2.1). Generic + column-map driven so the four sample
 * sources reuse one parser (DRY, §12); see CsvSourceCatalog for the maps.
 *
 * Robustness (§11): detects UTF-8 vs CP1251 and converts; strips BOM; detects
 * ';' vs ',' delimiter; reads records BY INDEX (not array_combine) so truncated
 * rows don't crash and extra columns are ignored; invalid/negative volume -> null.
 */
final class CsvSource implements KeywordSourceProvider
{
    private string $filePath;
    private string $sourceType;
    /** @var array<string,?string> canonical field => CSV header (or null if absent) */
    private array $columnMap;
    private ?string $fingerprint = null;

    public function __construct(string $filePath, string $sourceType, array $columnMap)
    {
        $this->filePath = $filePath;
        $this->sourceType = $sourceType;
        $this->columnMap = $columnMap;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function fingerprint(): string
    {
        if ($this->fingerprint === null) {
            $this->fingerprint = hash('sha256', $this->readRawBytes());
        }

        return $this->fingerprint;
    }

    public function rows(): iterable
    {
        $content = $this->stripBom($this->toUtf8($this->readRawBytes()));
        if (trim($content) === '') {
            return;
        }

        $reader = Reader::fromString($content);
        $reader->setDelimiter($this->detectDelimiter($content));
        $records = array_values(iterator_to_array($reader->getRecords(), false));
        if ($records === []) {
            return;
        }

        $headerIndex = array_flip(array_map('trim', $records[0]));
        $fieldIndex = [];
        foreach (KeywordSourceProvider::FIELDS as $field) {
            $header = $this->columnMap[$field] ?? null;
            $fieldIndex[$field] = ($header !== null && isset($headerIndex[$header])) ? $headerIndex[$header] : null;
        }

        foreach (array_slice($records, 1) as $record) {
            if (count($record) === 1 && trim((string) reset($record)) === '') {
                continue; // skip blank line
            }
            yield $this->mapRecord($record, $fieldIndex);
        }
    }

    private function readRawBytes(): string
    {
        return (string) file_get_contents($this->filePath);
    }

    /** Detect CP1251 (the project's likely non-UTF-8) and convert; pass UTF-8/ASCII through. */
    private function toUtf8(string $bytes): string
    {
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1251');
    }

    private function stripBom(string $content): string
    {
        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }

    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n") ?: '';

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param array<int,string> $record
     * @param array<string,?int> $fieldIndex
     * @return array{raw_keyword:string,search_volume:?int,source_country:?string,source_url:?string,source_language:?string}
     */
    private function mapRecord(array $record, array $fieldIndex): array
    {
        $value = static function (?int $index) use ($record): ?string {
            if ($index === null || !array_key_exists($index, $record)) {
                return null;
            }
            $trimmed = trim((string) $record[$index]);

            return $trimmed === '' ? null : $trimmed;
        };

        return [
            'raw_keyword' => (string) ($value($fieldIndex['raw_keyword']) ?? ''),
            'search_volume' => $this->parseVolume($value($fieldIndex['search_volume'])),
            'source_country' => $value($fieldIndex['source_country']),
            'source_url' => $value($fieldIndex['source_url']),
            'source_language' => $value($fieldIndex['source_language']),
        ];
    }

    /** Non-numeric, empty, or negative volume -> null (§11). */
    private function parseVolume(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $int = filter_var($raw, FILTER_VALIDATE_INT);

        return ($int === false || $int < 0) ? null : $int;
    }
}
