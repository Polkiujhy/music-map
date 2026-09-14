<?php

namespace App\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistWriter;
use InvalidArgumentException;

final readonly class SourcePlaylistWriterRegistry
{
    /** @var array<string, SourcePlaylistWriter> */
    private array $writers;

    /** @param iterable<SourcePlaylistWriter> $writers */
    public function __construct(iterable $writers)
    {
        $indexed = [];
        foreach ($writers as $writer) {
            $key = $writer->provider()->value;
            if (isset($indexed[$key])) {
                throw new InvalidArgumentException("A source playlist writer is already registered for {$key}.");
            }
            $indexed[$key] = $writer;
        }
        $this->writers = $indexed;
    }

    public function writerFor(StreamingProvider $provider): ?SourcePlaylistWriter
    {
        return $this->writers[$provider->value] ?? null;
    }
}
