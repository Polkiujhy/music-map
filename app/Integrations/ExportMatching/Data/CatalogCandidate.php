<?php

namespace App\Integrations\ExportMatching\Data;

final readonly class CatalogCandidate
{
    /** @param list<string> $creators */
    public function __construct(
        public string $catalogId,
        public string $catalogUri,
        public string $title,
        public array $creators,
        public ?string $album,
        public ?int $durationMilliseconds,
        public ?string $isrc = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (string) $value['catalogId'],
            (string) $value['catalogUri'],
            (string) $value['title'],
            array_values(array_filter($value['creators'] ?? [], 'is_string')),
            is_string($value['album'] ?? null) ? $value['album'] : null,
            is_int($value['durationMilliseconds'] ?? null) ? $value['durationMilliseconds'] : null,
            is_string($value['isrc'] ?? null) ? $value['isrc'] : null,
        );
    }
}
