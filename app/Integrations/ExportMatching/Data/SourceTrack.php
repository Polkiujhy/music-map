<?php

namespace App\Integrations\ExportMatching\Data;

use App\Integrations\ExportMatching\MatchNormalizer;
use App\Models\ExportReviewItem;

final readonly class SourceTrack
{
    private const FINGERPRINT_VERSION = 'catalog-match:v1';

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
        $json = json_encode([
            'title' => MatchNormalizer::text($this->title),
            'creators' => MatchNormalizer::names($this->creators),
            'album' => MatchNormalizer::text($this->album),
            'duration_milliseconds' => $this->durationMilliseconds,
            'isrc' => MatchNormalizer::text($this->isrc),
            'available' => $this->available,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', self::FINGERPRINT_VERSION."\n".$json);
    }
}
