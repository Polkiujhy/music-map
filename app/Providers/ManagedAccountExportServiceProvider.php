<?php

namespace App\Providers;

use App\Integrations\ManagedAccountExport\Contracts\WithManagedAccountAccess as WithManagedAccountAccessContract;
use App\Integrations\ManagedAccountExport\ManagedPlaylistGatewayRegistry;
use App\Integrations\ManagedAccountExport\Providers\SpotifyManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Providers\YouTubeManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\WithManagedAccountAccess;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\UnixManagedExportAccessBroker;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ManagedAccountExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ManagedExportAccessBroker::class, function (): ManagedExportAccessBroker {
            $socket = config('services.managed_export.socket');

            return new UnixManagedExportAccessBroker(is_string($socket) ? $socket : '');
        });
        $this->app->bind(WithManagedAccountAccessContract::class, WithManagedAccountAccess::class);
        $this->app->singleton(SpotifyManagedPlaylistGateway::class);
        $this->app->singleton(YouTubeManagedPlaylistGateway::class);
        $this->app->singleton(
            ManagedPlaylistGatewayRegistry::class,
            fn (Application $app): ManagedPlaylistGatewayRegistry => new ManagedPlaylistGatewayRegistry(
                $app->make(SpotifyManagedPlaylistGateway::class),
                $app->make(YouTubeManagedPlaylistGateway::class),
            ),
        );
    }
}
