<?php

namespace App\Integrations\PlaylistImport\Data;

use App\Enums\StreamingProvider;

final readonly class PlaylistReference
{
    public function __construct(
        public StreamingProvider $provider,
        public string $providerPlaylistId,
        public string $canonicalUrl,
    ) {}
}
