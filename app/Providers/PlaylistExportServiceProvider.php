<?php

namespace App\Providers;

use App\Integrations\PlaylistExport\PlaylistWriterRegistry;
use App\Integrations\PlaylistExport\Providers\SpotifyPlaylistWriter;
use App\Integrations\PlaylistExport\Providers\YouTubePlaylistWriter;
use Illuminate\Support\ServiceProvider;

final class PlaylistExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A scoped lifetime makes the 360-second I/O budget span every adapter
        // call made by one queue invocation without leaking into the next job.
        $this->app->scoped(SpotifyPlaylistWriter::class);
        $this->app->scoped(YouTubePlaylistWriter::class);
        $this->app->scoped(PlaylistWriterRegistry::class);
    }
}
