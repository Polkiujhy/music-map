<?php

namespace App\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistReader;
use InvalidArgumentException;

final readonly class SourcePlaylistReaderRegistry
{
    /** @var array<string, SourcePlaylistReader> */
    private array $readers;

    /** @param iterable<SourcePlaylistReader> $readers */
    public function __construct(iterable $readers)
    {
        $indexed = [];

        foreach ($readers as $reader) {
            $key = $reader->provider()->value;

            if (isset($indexed[$key])) {
                throw new InvalidArgumentException("A source playlist reader is already registered for {$key}.");
            }

            $indexed[$key] = $reader;
        }

        $this->readers = $indexed;
    }

    public function readerFor(StreamingProvider $provider): ?SourcePlaylistReader
    {
        return $this->readers[$provider->value] ?? null;
    }
}
