<?php

namespace App\Providers;

use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\UnixManagedExportAccessBroker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ManagedExportAccessBroker::class,
            fn (): UnixManagedExportAccessBroker => new UnixManagedExportAccessBroker(
                (string) config('services.managed_export.socket'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
