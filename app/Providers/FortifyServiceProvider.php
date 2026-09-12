<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\NeutralPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn () => view('pages.auth.login'));
        Fortify::registerView(fn () => view('pages.auth.register'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages.auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('pages.auth.reset-password', [
            'request' => $request,
        ]));
        Fortify::verifyEmailView(fn () => view('pages.auth.verify-email'));

        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            NeutralPasswordResetLinkResponse::class,
        );

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = strtolower(trim((string) $request->input('email')));
            $user = User::query()->where('email', $email)->first();

            return $user !== null && $user->password !== null && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(strtolower(trim((string) $request->input('email'))).'|'.$request->ip());
        });

        RateLimiter::for('google-oauth', function (Request $request): Limit {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
