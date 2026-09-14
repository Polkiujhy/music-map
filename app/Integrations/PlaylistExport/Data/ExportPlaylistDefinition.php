<?php

namespace App\Integrations\PlaylistExport\Data;

use App\Enums\StreamingProvider;

final readonly class ExportPlaylistDefinition
{
    /**
     * @param  list<string>  $items
     */
    public function __construct(
        public StreamingProvider $provider,
        public string $targetAccountId,
        public ?string $targetId,
        public string $name,
        public string $description,
        public string $marker,
        public string $visibility,
        public array $items,
    ) {}

    public function markedDescription(): string
    {
        return $this->description === ''
            ? $this->marker
            : $this->description."\n\n".$this->marker;
    }
}
