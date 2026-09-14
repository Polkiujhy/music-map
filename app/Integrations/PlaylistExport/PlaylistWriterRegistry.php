<?php

namespace App\Integrations\PlaylistExport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Contracts\PlaylistWriter;
use App\Integrations\PlaylistExport\Providers\SpotifyPlaylistWriter;
use App\Integrations\PlaylistExport\Providers\YouTubePlaylistWriter;

final readonly class PlaylistWriterRegistry
{
    public function __construct(
        private SpotifyPlaylistWriter $spotify,
        private YouTubePlaylistWriter $youTube,
    ) {}

    public function writerFor(StreamingProvider $provider): PlaylistWriter
    {
        return match ($provider) {
            StreamingProvider::Spotify => $this->spotify,
            StreamingProvider::YouTube => $this->youTube,
        };
    }
}
