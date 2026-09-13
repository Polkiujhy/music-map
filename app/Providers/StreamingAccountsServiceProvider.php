<?php

namespace App\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Actions\NoopDisableDependentStreamingSynchronizations;
use App\Integrations\StreamingAccounts\Actions\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess as WithStreamingAccessContract;
use App\Integrations\StreamingAccounts\SpotifyOAuthGateway;
use App\Integrations\StreamingAccounts\YouTubeOAuthGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class StreamingAccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('streaming-oauth.spotify', SpotifyOAuthGateway::class);
        $this->app->bind('streaming-oauth.youtube', YouTubeOAuthGateway::class);
        $this->app->bind(
            DisableDependentStreamingSynchronizations::class,
            NoopDisableDependentStreamingSynchronizations::class,
        );
        $this->app->bind(WithStreamingAccessContract::class, WithStreamingAccess::class);
    }

    public function boot(): void
    {
        RateLimiter::for('streaming-oauth', function (Request $request): Limit {
            $provider = $this->providerFor($request);

            return Limit::perMinute(10)->by(
                (string) $request->user()?->getAuthIdentifier().'|'.$provider,
            );
        });
    }

    private function providerFor(Request $request): string
    {
        if ($request->routeIs('integrations.accounts.verify')) {
            $accountId = $request->route('streamingAccount');

            if (! is_scalar($accountId) || $request->user() === null) {
                return 'unknown';
            }

            $provider = $request->user()
                ->streamingAccounts()
                ->whereKey((string) $accountId)
                ->value('provider');

            return is_string($provider) && StreamingProvider::tryFrom($provider) !== null
                ? $provider
                : 'unknown';
        }

        $provider = $request->route('provider');

        return is_string($provider) && StreamingProvider::tryFrom($provider) !== null
            ? $provider
            : 'unknown';
    }
}
