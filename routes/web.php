<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\PlaylistImportController;
use App\Http\Controllers\StreamingAccountController;
use App\Http\Controllers\StreamingAccountOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');

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
    Route::delete('/integrations/{streamingAccount}', [StreamingAccountController::class, 'destroy'])
        ->name('integrations.destroy');
    Route::post('/integrations/{provider}/connect', [StreamingAccountOAuthController::class, 'connect'])
        ->whereIn('provider', ['spotify', 'youtube'])
        ->middleware('throttle:streaming-oauth')
        ->block(5, 5)
        ->name('integrations.connect');
    Route::get('/integrations/{provider}/callback', [StreamingAccountOAuthController::class, 'callback'])
        ->whereIn('provider', ['spotify', 'youtube'])
        ->middleware('throttle:streaming-oauth')
        ->block(30, 30)
        ->name('integrations.callback');
    Route::post('/integrations/{streamingAccount}/verify', [StreamingAccountOAuthController::class, 'verify'])
        ->middleware('throttle:streaming-oauth')
        ->name('integrations.accounts.verify');
    Route::get('/bank', [BankController::class, 'index'])->name('bank.index');
    Route::post('/bank/import', [PlaylistImportController::class, 'store'])
        ->middleware('throttle:playlist-import')
        ->name('playlists.import');
});
