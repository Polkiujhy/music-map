<?php

namespace App\Integrations\PlaylistImport\Data;

use InvalidArgumentException;

final readonly class PlaylistItemSnapshot
{
    /**
     * @param  list<string>  $creators
     */
    public function __construct(
        public ?string $occurrenceId,
        public ?string $catalogId,
        public ?string $catalogUri,
        public ?string $title,
        public array $creators,
        public ?string $album,
        public ?int $durationMilliseconds,
        public ?string $isrc,
        public int $position,
        public bool $isAvailable,
    ) {
        if ($position < 0) {
            throw new InvalidArgumentException('Playlist item position must be zero or greater.');
        }

        if ($durationMilliseconds !== null && $durationMilliseconds < 0) {
            throw new InvalidArgumentException('Playlist item duration must be zero or greater.');
        }

        if (! array_is_list($creators)) {
            throw new InvalidArgumentException('Playlist item creators must be ordered.');
        }

        foreach ($creators as $creator) {
            if (! is_string($creator)) {
                throw new InvalidArgumentException('Playlist item creators must be strings.');
            }
        }
    }
}
