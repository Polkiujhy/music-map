<?php

namespace App\Integrations\ManagedAccountExport\Data;

use InvalidArgumentException;

final readonly class ManagedPlaylistSnapshot
{
    /** @param list<ManagedPlaylistItem> $items */
    public function __construct(
        public ManagedPlaylistReference $reference,
        public ManagedPlaylistMetadata $metadata,
        public string $visibility,
        public array $items,
        public ?string $revision = null,
    ) {
        if (count($items) > 20) {
            throw new InvalidArgumentException('Managed exports contain at most twenty items.');
        }
        foreach ($items as $position => $item) {
            if (! $item instanceof ManagedPlaylistItem || $item->position !== $position) {
                throw new InvalidArgumentException('Managed playlist items must be typed and ordered.');
            }
        }
    }
}
