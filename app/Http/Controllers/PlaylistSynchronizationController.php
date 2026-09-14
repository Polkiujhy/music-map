<?php

namespace App\Http\Controllers;

use App\Actions\PlaylistSync\ConfirmPlaylistSynchronization;
use App\Actions\PlaylistSync\DispatchPlaylistSynchronization;
use App\Actions\PlaylistSync\PreparePlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Http\Requests\ConfirmPlaylistSynchronizationRequest;
use App\Http\Requests\UpdatePlaylistSynchronizationRequest;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaylistSynchronizationController extends Controller
{
    public function prepare(
        Request $request,
        string $playlist,
        PreparePlaylistSynchronization $prepare,
    ): JsonResponse|RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);

        try {
            $preview = $prepare->handle($request->user(), $ownedPlaylist);
        } catch (ValidationException $exception) {
            $this->pauseAfterFailedPreparation($ownedPlaylist, $exception);

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json($preview);
        }

        return to_route('bank.playlists.edit', $ownedPlaylist)
            ->with('playlist_sync_preview', $preview);
    }

    public function confirm(
        ConfirmPlaylistSynchronizationRequest $request,
        string $playlist,
        ConfirmPlaylistSynchronization $confirm,
    ): JsonResponse|RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $run = $confirm->handle($request->user(), $ownedPlaylist, $request->previewToken());

        $result = [
            'synchronization_id' => (int) $run->playlist_synchronization_id,
            'run_id' => (int) $run->getKey(),
            'status' => 'queued',
            'automatic_enabled' => false,
        ];

        if ($request->expectsJson()) {
            return response()->json($result, 202);
        }

        return to_route('bank.playlists.edit', $ownedPlaylist)
            ->with('status', 'Synchronizacja została włączona. Pierwsze uzgodnienie oczekuje w kolejce; tryb automatyczny pozostaje wyłączony.');
    }

    public function show(Request $request, string $playlist): JsonResponse
    {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $synchronization = $ownedPlaylist->synchronization()
            ->with(['runs' => fn ($query) => $query->latest('id')->limit(1)])
            ->first();

        return response()->json([
            'provider' => $ownedPlaylist->source_provider->value,
            'state' => $this->displayState($synchronization),
            'automatic_enabled' => (bool) ($synchronization?->automatic_enabled ?? false),
            'last_checked_at' => $synchronization?->last_checked_at?->toIso8601String(),
            'last_result' => $synchronization?->last_outcome?->value,
            'attention_message' => $this->attentionMessage($synchronization?->last_failure_code),
        ]);
    }

    public function run(
        Request $request,
        string $playlist,
        DispatchPlaylistSynchronization $dispatch,
    ): JsonResponse|RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $synchronization = $ownedPlaylist->synchronization()->first();

        if ($synchronization?->status !== PlaylistSyncStatus::Enabled) {
            throw ValidationException::withMessages([
                'synchronization' => 'Najpierw przygotuj i potwierdź synchronizację.',
            ]);
        }

        $run = $dispatch->handle((int) $synchronization->getKey(), PlaylistSyncTrigger::Manual);
        $queued = $run !== null;
        $message = $queued
            ? 'Ręczna synchronizacja oczekuje w kolejce.'
            : 'Synchronizacja już oczekuje lub trwa.';

        if ($request->expectsJson()) {
            return response()->json(['status' => $queued ? 'queued' : 'already-active'], 202);
        }

        return to_route('bank.playlists.edit', $ownedPlaylist)->with('status', $message);
    }

    public function update(
        UpdatePlaylistSynchronizationRequest $request,
        string $playlist,
    ): JsonResponse|RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $automaticEnabled = $request->automaticEnabled();

        $synchronization = DB::transaction(function () use ($ownedPlaylist, $automaticEnabled) {
            $synchronization = $ownedPlaylist->synchronization()->lockForUpdate()->first();
            if ($synchronization === null || ($automaticEnabled && $synchronization->status !== PlaylistSyncStatus::Enabled)) {
                throw ValidationException::withMessages([
                    'automatic_enabled' => 'Tryb automatyczny można włączyć po potwierdzeniu sprawnej synchronizacji.',
                ]);
            }

            $synchronization->update([
                'automatic_enabled' => $automaticEnabled,
                'next_check_at' => $automaticEnabled ? now() : null,
            ]);

            return $synchronization;
        });

        if ($request->expectsJson()) {
            return response()->json([
                'automatic_enabled' => $synchronization->automatic_enabled,
            ]);
        }

        return to_route('bank.playlists.edit', $ownedPlaylist)->with(
            'status',
            $automaticEnabled
                ? 'Automatyczna synchronizacja została włączona.'
                : 'Automatyczna synchronizacja została wyłączona.',
        );
    }

    private function playlist(Request $request, string $playlist): Playlist
    {
        abort_unless(ctype_digit($playlist) && (int) $playlist > 0, 404);

        return $request->user()->playlists()->sourceOnly()->whereKey($playlist)->firstOrFail();
    }

    private function displayState(?PlaylistSynchronization $synchronization): string
    {
        if ($synchronization === null) {
            return 'disabled';
        }

        $activeState = $synchronization->runs->first()?->state;

        return match (true) {
            $activeState === 'running' => 'running',
            $activeState === 'pending' => 'pending',
            $synchronization->status === PlaylistSyncStatus::Attention => 'attention',
            $synchronization->status === PlaylistSyncStatus::Disabled => 'disabled',
            $synchronization->status === PlaylistSyncStatus::PendingConfirmation => 'pending-confirmation',
            $synchronization->last_outcome !== null => 'success',
            default => 'enabled',
        };
    }

    private function pauseAfterFailedPreparation(Playlist $playlist, ValidationException $exception): void
    {
        $synchronization = $playlist->synchronization()->first();
        if ($synchronization === null || $synchronization->status === PlaylistSyncStatus::Disabled) {
            return;
        }

        $synchronization->update([
            'status' => PlaylistSyncStatus::Attention,
            'automatic_enabled' => false,
            'next_check_at' => null,
            'last_failure_code' => $this->preparationFailureCode($exception),
        ]);
    }

    private function preparationFailureCode(ValidationException $exception): string
    {
        $message = implode(' ', array_merge(...array_values($exception->errors())));

        return match (true) {
            str_contains($message, 'maksymalnie 20') => 'over-limit',
            str_contains($message, 'nie istnieje') => 'not-found',
            str_contains($message, 'ponownego połączenia') => 'reconnect-required',
            str_contains($message, 'właścicielem'), str_contains($message, 'należącą') => 'owner-mismatch',
            str_contains($message, 'ograniczył liczbę żądań') => 'rate-limited',
            str_contains($message, 'Połącz konto') => 'missing-access',
            str_contains($message, 'chwilowo niedostępny') => 'provider-unavailable',
            default => 'invalid-response',
        };
    }

    private function attentionMessage(?string $failureCode): ?string
    {
        if ($failureCode === null) {
            return null;
        }

        return match ($failureCode) {
            'over-limit' => 'Playlista źródłowa przekracza limit 20 pozycji. Zmniejsz ją, a następnie przygotuj synchronizację ponownie.',
            'not-found' => 'Nie można odnaleźć playlisty źródłowej. Sprawdź ją na platformie i przygotuj synchronizację ponownie.',
            'unauthorized', 'forbidden', 'owner-mismatch', 'missing-access', 'reconnect-required' => 'Połącz konto ponownie, a następnie przygotuj i potwierdź synchronizację.',
            'rate-limited' => 'Platforma chwilowo ograniczyła żądania. Spróbuj ponownie później.',
            'quota-limited' => 'Dzienny limit YouTube został wyczerpany. Spróbuj ponownie po odnowieniu limitu.',
            'write-admission-limited' => 'Dzienny limit zapisów aplikacji został osiągnięty. Spróbuj ponownie później.',
            'provider-unavailable' => 'Platforma jest chwilowo niedostępna. Spróbuj przygotować synchronizację ponownie później.',
            default => 'Synchronizacja wymaga ponownego przygotowania i potwierdzenia.',
        };
    }
}
