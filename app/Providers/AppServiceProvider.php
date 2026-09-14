<?php

namespace App\Providers;

use App\Listeners\DispatchDuePlaylistSynchronizationsAfterLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, DispatchDuePlaylistSynchronizationsAfterLogin::class);
    }
}
