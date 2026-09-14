<?php

namespace App\Integrations\ExportMatching\Data;

use App\Models\ExportReviewItem;

final readonly class SourceTrack
{
    /** @param list<string> $creators */
    public function __construct(
        public int $position,
        public ?string $catalogId,
        public ?string $title,
        public array $creators,
        public ?string $album,
        public ?int $durationMilliseconds,
        public ?string $isrc,
        public bool $available,
    ) {}

    public static function fromReviewItem(ExportReviewItem $item): self
    {
        return new self(
            (int) $item->position,
            $item->source_catalog_id,
            $item->source_title,
            $item->source_creators ?? [],
            $item->source_album,
            $item->source_duration_milliseconds,
            $item->source_isrc,
            (bool) $item->source_is_available,
        );
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'catalog_id' => $this->catalogId,
            'title' => $this->title,
            'creators' => $this->creators,
            'album' => $this->album,
            'duration' => $this->durationMilliseconds,
            'isrc' => $this->isrc,
            'available' => $this->available,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
