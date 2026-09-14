<?php

namespace App\Integrations\PlaylistImport\Data;

use App\Enums\StreamingProvider;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PlaylistSnapshot
{
    /**
     * @param  list<PlaylistItemSnapshot>  $items
     */
    public function __construct(
        public StreamingProvider $provider,
        public string $providerPlaylistId,
        public ?string $sourceAccountId,
        public string $canonicalUrl,
        public ?string $providerRevision,
        public ?string $name,
        public ?string $description,
        public DateTimeImmutable $providerMetadataRefreshedAt,
        public array $items,
    ) {
        if ($providerPlaylistId === '' || strlen($canonicalUrl) > 512) {
            throw new InvalidArgumentException('Snapshot source reference is invalid.');
        }

        if (count($items) > 20) {
            throw new InvalidArgumentException('A playlist snapshot cannot contain more than 20 items.');
        }

        if (! array_is_list($items)) {
            throw new InvalidArgumentException('Snapshot items must be ordered.');
        }

        foreach ($items as $position => $item) {
            if (! $item instanceof PlaylistItemSnapshot) {
                throw new InvalidArgumentException('Snapshot items must be playlist item snapshots.');
            }

            if ($item->position !== $position) {
                throw new InvalidArgumentException('Snapshot item positions must be contiguous and zero-based.');
            }
        }
    }
}
