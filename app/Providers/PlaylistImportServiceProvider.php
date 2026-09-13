<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PlaylistImportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('playlist-import', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->user()?->getAuthIdentifier());
        });
    }
}
