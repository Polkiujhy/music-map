<?php

namespace App\Providers;

use App\Integrations\ExportMatching\SpotifyClientCredentials;
use Illuminate\Support\ServiceProvider;

final class ExportMatchingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One scoped instance ensures a client-credentials token never leaves
        // the current worker invocation or enters persistent cache.
        $this->app->scoped(SpotifyClientCredentials::class);
    }
}
