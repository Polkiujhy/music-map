<?php

namespace App\Actions\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\Playlist;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmPlaylistSynchronization
{
    public function __construct(
        private SourcePlaylistReaderRegistry $readers,
        private WithStreamingAccess $withStreamingAccess,
        private FingerprintPlaylistContent $bankFingerprint,
        private FingerprintSourcePlaylist $sourceFingerprint,
        private DispatchPlaylistSynchronization $dispatch,
    ) {}

    public function handle(User $user, Playlist $playlist, string $previewToken): PlaylistSyncRun
    {
        $preview = $this->consumePreview(
            $previewToken,
            (int) $user->getKey(),
            (int) $playlist->getKey(),
        );

        $playlist = $user->playlists()->whereKey($playlist->getKey())->with('items')->firstOrFail();
        $account = $user->streamingAccounts()
            ->whereKey($preview['streaming_account_id'] ?? null)
            ->where('provider', $playlist->source_provider->value)
            ->first();
        if (! $account instanceof StreamingAccount) {
            $this->stale();
        }

        if (! hash_equals((string) $preview['bank_fingerprint'], $this->bankFingerprint->handle($playlist))) {
            $this->stale();
        }

        $reader = $this->readers->readerFor($playlist->source_provider);
        if ($reader === null) {
            $this->stale();
        }

        $snapshot = null;
        $result = $this->withStreamingAccess->handle(
            $user,
            $account,
            $playlist->source_provider->requiredScopes(),
            function (StreamingAccessContext $access) use ($reader, $playlist, &$snapshot): void {
                $snapshot = $reader->read($playlist->source_playlist_id, $access);
            },
        );

        if (! $result->successful || $snapshot instanceof SourceSyncFailure || ! $snapshot instanceof SourcePlaylistSnapshot) {
            $this->stale();
        }

        $sourceFingerprint = $this->sourceFingerprint->handle($snapshot);
        if (! hash_equals((string) $preview['source_fingerprint'], $sourceFingerprint)
            || ($preview['provider_revision'] ?? null) !== $snapshot->providerRevision
            || ! hash_equals($account->provider_account_id, (string) $snapshot->ownerAccountId)) {
            $this->stale();
        }

        $run = DB::transaction(function () use ($user, $playlist, $account, $preview, $sourceFingerprint): PlaylistSyncRun {
            $locked = Playlist::query()->whereKey($playlist->getKey())->lockForUpdate()->with('items')->firstOrFail();
            if ((int) $locked->user_id !== (int) $user->getKey()
                || ! hash_equals((string) $preview['bank_fingerprint'], $this->bankFingerprint->handle($locked))) {
                $this->stale();
            }

            $sync = $locked->synchronization()->updateOrCreate([], [
                'streaming_account_id' => $account->getKey(),
                'status' => PlaylistSyncStatus::Enabled,
                'automatic_enabled' => false,
                'last_failure_code' => null,
            ]);

            return $sync->runs()->create([
                'operation_id' => (string) Str::uuid(),
                'trigger' => PlaylistSyncTrigger::Activation,
                'direction' => PlaylistSyncDirection::from((string) $preview['direction']),
                'state' => 'pending',
                'input_bank_fingerprint' => $preview['bank_fingerprint'],
                'input_source_fingerprint' => $sourceFingerprint,
                'input_provider_revision' => $preview['provider_revision'] ?? null,
                'bank_snapshot' => $preview['bank_snapshot'],
                'source_snapshot' => $preview['source_snapshot'],
                'desired_fingerprint' => match ($preview['direction']) {
                    PlaylistSyncDirection::Pull->value => $sourceFingerprint,
                    PlaylistSyncDirection::Push->value => $preview['bank_fingerprint'],
                    default => null,
                },
                'checkpoint' => null,
            ]);
        });

        $this->dispatch->dispatchRun($run);

        return $run;
    }

    /** @return array<string, mixed> */
    private function consumePreview(string $token, int $userId, int $playlistId): array
    {
        $key = PreparePlaylistSynchronization::cacheKey($token);
        $lock = Cache::lock($key.':confirm-lock', 10);

        if (! $lock->get()) {
            $this->stale();
        }

        try {
            $preview = Cache::get($key);
            if (! is_array($preview)
                || ($preview['user_id'] ?? null) !== $userId
                || ($preview['playlist_id'] ?? null) !== $playlistId) {
                $this->stale();
            }

            Cache::forget($key);

            return $preview;
        } finally {
            $lock->release();
        }
    }

    private function stale(): never
    {
        throw ValidationException::withMessages([
            'preview_token' => 'Podgląd jest nieaktualny. Przygotuj synchronizację ponownie.',
        ]);
    }
}
