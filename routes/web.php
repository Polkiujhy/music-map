<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\ManagedExportController;
use App\Http\Controllers\PlaylistEditingController;
use App\Http\Controllers\PlaylistExportReviewController;
use App\Http\Controllers\PlaylistImportController;
use App\Http\Controllers\PlaylistReimportController;
use App\Http\Controllers\StreamingAccountController;
use App\Http\Controllers\StreamingAccountOAuthController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

RateLimiter::for('export-review-start', function (Request $request): Limit {
    return Limit::perMinute(5)->by(implode('|', [
        (string) $request->user()?->getAuthIdentifier(),
        (string) $request->input('target_provider'),
    ]));
});

RateLimiter::for('export-review-retry', function (Request $request): Limit {
    $provider = $request->user()?->exportReviews()
        ->whereKey($request->route('exportReview'))
        ->value('target_provider');
    $providerKey = $provider instanceof BackedEnum ? $provider->value : (string) ($provider ?? 'unknown');

    return Limit::perMinute(3)->by(implode('|', [
        (string) $request->user()?->getAuthIdentifier(),
        $providerKey,
    ]));
});

RateLimiter::for('managed-export-action', function (Request $request): Limit {
    $provider = $request->user()?->exportOperations()
        ->whereKey($request->route('exportOperation'))
        ->with('playlistExport:id,target_provider')
        ->first()?->playlistExport?->target_provider;
    $providerKey = $provider instanceof BackedEnum ? $provider->value : (string) ($provider ?? 'unknown');

    return Limit::perMinute(3)->by(implode('|', [
        (string) $request->user()?->getAuthIdentifier(),
        $providerKey,
    ]));
});

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
    Route::get('/bank/playlists/{playlist}/edit', PlaylistEditingController::class)
        ->whereNumber('playlist')
        ->name('bank.playlists.edit');
    Route::post('/bank/import', [PlaylistImportController::class, 'store'])
        ->middleware('throttle:playlist-import')
        ->name('playlists.import');
    Route::post('/bank/playlists/{playlist}/reimport', PlaylistReimportController::class)
        ->whereNumber('playlist')
        ->middleware('throttle:playlist-import')
        ->name('bank.playlists.reimport');
    Route::post('/bank/playlists/{playlist}/export-reviews', [PlaylistExportReviewController::class, 'store'])
        ->middleware('throttle:export-review-start')
        ->name('export-reviews.store');
    Route::get('/bank/playlists/{playlist}/export-reviews/{exportReview}', [PlaylistExportReviewController::class, 'show'])
        ->name('export-reviews.show');
    Route::post('/bank/playlists/{playlist}/export-reviews/{exportReview}/retry', [PlaylistExportReviewController::class, 'retry'])
        ->middleware('throttle:export-review-retry')
        ->name('export-reviews.retry');
    Route::post('/bank/playlists/{playlist}/export-reviews/{exportReview}/confirm', [PlaylistExportReviewController::class, 'confirm'])
        ->name('export-reviews.confirm');
    Route::post('/bank/playlists/{playlist}/managed-exports/{exportOperation}/retry', [ManagedExportController::class, 'retry'])
        ->whereNumber('playlist')
        ->middleware('throttle:managed-export-action')
        ->name('managed-exports.retry');
    Route::post('/bank/playlists/{playlist}/managed-exports/{exportOperation}/recreate', [ManagedExportController::class, 'recreate'])
        ->whereNumber('playlist')
        ->middleware('throttle:managed-export-action')
        ->name('managed-exports.recreate');
});
