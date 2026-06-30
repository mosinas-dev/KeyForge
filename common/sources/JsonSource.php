<?php

declare(strict_types=1);

namespace common\sources;

use InvalidArgumentException;

/**
 * JSON keyword source: a JSON array of objects, mapped to canonical fields via a
 * column map (object key per field). Second provider under KeywordSourceProvider
 * (OCP, §12) — added without touching CsvSource.
 */
final class JsonSource implements KeywordSourceProvider
{
    use SourceValueParser;

    private ?string $fingerprint = null;

    /** @param array<string,?string> $columnMap canonical field => JSON object key (or null if absent) */
    public function __construct(
        private string $filePath,
        private string $sourceType,
        private array $columnMap,
    ) {
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function fingerprint(): string
    {
        if ($this->fingerprint === null) {
            $this->fingerprint = hash('sha256', (string) file_get_contents($this->filePath));
        }

        return $this->fingerprint;
    }

    public function rows(): iterable
    {
        $decoded = json_decode((string) file_get_contents($this->filePath), true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Invalid JSON in source '{$this->filePath}': expected an array of objects");
        }

        foreach ($decoded as $object) {
            if (!is_array($object)) {
                continue;
            }
            yield $this->mapObject($object);
        }
    }

    /**
     * @param array<string,mixed> $object
     * @return array{raw_keyword:string,search_volume:?int,source_country:?string,source_url:?string,source_language:?string}
     */
    private function mapObject(array $object): array
    {
        $field = fn (string $name): mixed =>
            ($this->columnMap[$name] ?? null) !== null ? ($object[$this->columnMap[$name]] ?? null) : null;

        return [
            'raw_keyword' => (string) ($this->cleanString($field('raw_keyword')) ?? ''),
            'search_volume' => $this->parseVolume($field('search_volume')),
            'source_country' => $this->cleanString($field('source_country')),
            'source_url' => $this->cleanString($field('source_url')),
            'source_language' => $this->cleanString($field('source_language')),
        ];
    }
}
