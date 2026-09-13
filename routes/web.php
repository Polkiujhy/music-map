<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\StreamingAccountController;
use App\Http\Controllers\StreamingAccountOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:google-oauth')
        ->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:google-oauth')
        ->name('auth.google.callback');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/integrations', [StreamingAccountController::class, 'index'])
        ->name('integrations.index');
    Route::post('/integrations/{provider}/connect', [StreamingAccountOAuthController::class, 'connect'])
        ->whereIn('provider', ['spotify', 'youtube'])
        ->middleware('throttle:streaming-oauth')
        ->name('integrations.connect');
    Route::get('/integrations/{provider}/callback', [StreamingAccountOAuthController::class, 'callback'])
        ->whereIn('provider', ['spotify', 'youtube'])
        ->middleware('throttle:streaming-oauth')
        ->name('integrations.callback');
    Route::post('/integrations/{streamingAccount}/verify', [StreamingAccountOAuthController::class, 'verify'])
        ->middleware('throttle:streaming-oauth')
        ->name('integrations.accounts.verify');
    Route::get('/bank', fn () => view('bank.index'))->name('bank.index');
});
