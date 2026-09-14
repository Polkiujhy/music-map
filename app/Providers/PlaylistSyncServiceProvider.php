<?php

namespace App\Providers;

use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistWriter;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourcePlaylistWriterRegistry;
use Illuminate\Support\ServiceProvider;

final class PlaylistSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourcePlaylistReaderRegistry::class, fn ($app) => new SourcePlaylistReaderRegistry([
            $app->make(SpotifySourcePlaylistReader::class),
            $app->make(YouTubeSourcePlaylistReader::class),
        ]));
        $this->app->singleton(SourcePlaylistWriterRegistry::class, fn ($app) => new SourcePlaylistWriterRegistry([
            $app->make(SpotifySourcePlaylistWriter::class),
            $app->make(YouTubeSourcePlaylistWriter::class),
        ]));
    }
}
