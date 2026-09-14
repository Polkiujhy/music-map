<?php

namespace App\Providers;

use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use Illuminate\Support\ServiceProvider;

final class PlaylistSyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SourcePlaylistReaderRegistry::class, fn ($app) => new SourcePlaylistReaderRegistry([
            $app->make(SpotifySourcePlaylistReader::class),
            $app->make(YouTubeSourcePlaylistReader::class),
        ]));
    }
}
