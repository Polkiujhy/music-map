<?php

namespace App\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Contracts\PlaylistSourceReader;
use App\Integrations\PlaylistImport\Providers\SpotifyPlaylistReader;
use App\Integrations\PlaylistImport\Providers\YouTubePlaylistReader;

final readonly class PlaylistSourceReaderRegistry
{
    public function __construct(
        private YouTubePlaylistReader $youTube,
        private SpotifyPlaylistReader $spotify,
    ) {}

    public function readerFor(StreamingProvider $provider): ?PlaylistSourceReader
    {
        return match ($provider) {
            StreamingProvider::YouTube => $this->youTube,
            StreamingProvider::Spotify => $this->spotify,
        };
    }
}
