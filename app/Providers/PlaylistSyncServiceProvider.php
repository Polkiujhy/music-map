<?php

namespace App\Providers;

use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistWriter;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourcePlaylistWriterRegistry;
use BackedEnum;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

    public function boot(): void
    {
        RateLimiter::for('playlist-sync-provider', function (Request $request): Limit {
            $provider = $request->user()?->playlists()
                ->whereKey($request->route('playlist'))
                ->value('source_provider');
            $providerKey = $provider instanceof BackedEnum
                ? $provider->value
                : (is_string($provider) ? $provider : 'unknown');

            return Limit::perMinute(5)->by(implode('|', [
                (string) $request->user()?->getAuthIdentifier(),
                $providerKey,
            ]));
        });
    }
}
