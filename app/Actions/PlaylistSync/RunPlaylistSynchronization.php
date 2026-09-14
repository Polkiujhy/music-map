<?php

namespace App\Actions\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourcePlaylistWriterRegistry;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\Playlist;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RunPlaylistSynchronization
{
    public function __construct(
        private WithStreamingAccess $withStreamingAccess,
        private SourcePlaylistReaderRegistry $readers,
        private SourcePlaylistWriterRegistry $writers,
        private DeterminePlaylistSyncDirection $determineDirection,
        private FingerprintPlaylistContent $bankFingerprint,
        private FingerprintSourcePlaylist $sourceFingerprint,
        private CompletePlaylistSyncRun $complete,
        private FailPlaylistSyncRun $fail,
    ) {}

    public function handle(int $runId): ?SourceSyncFailure
    {
        $identity = PlaylistSyncRun::query()->whereKey($runId)->with('synchronization.playlist')->first();
        if (! $identity instanceof PlaylistSyncRun) {
            return null;
        }
        $sync = $identity->synchronization;
        $playlist = $sync->playlist;
        $account = StreamingAccount::query()->find($sync->streaming_account_id);
        $owner = User::query()->find($playlist->user_id);
        $reader = $this->readers->readerFor($playlist->source_provider);
        if (! $account instanceof StreamingAccount || ! $owner instanceof User || $reader === null) {
            $this->fail->handle($runId, 'missing-access');

            return null;
        }
        $accountId = (int) $account->getKey();

        $outcome = null;
        $accessResult = $this->withStreamingAccess->handle(
            $owner,
            $account,
            $playlist->source_provider->requiredScopes(),
            function (StreamingAccessContext $access) use ($runId, $reader, $playlist, $accountId, &$outcome): void {
                $source = $reader->read($playlist->source_playlist_id, $access);
                if (! $source instanceof SourcePlaylistSnapshot) {
                    $outcome = $source;

                    return;
                }
                if (! hash_equals($access->providerAccountId, (string) $source->ownerAccountId)) {
                    $outcome = SourceSyncFailure::OwnerMismatch;

                    return;
                }

                $prepared = DB::transaction(function () use ($runId, $source, $accountId): ?array {
                    $run = PlaylistSyncRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                    if (! in_array($run->state, ['pending', 'running'], true)) {
                        return ['skip' => true];
                    }
                    $sync = $run->synchronization()->lockForUpdate()->firstOrFail();
                    if ($sync->status !== PlaylistSyncStatus::Enabled
                        || (int) $sync->streaming_account_id !== $accountId) {
                        $run->update(['state' => 'cancelled']);

                        return ['skip' => true];
                    }
                    $activeRunId = $sync->runs()
                        ->whereIn('state', ['pending', 'running'])
                        ->orderBy('id')
                        ->value('id');
                    if ((int) $activeRunId !== (int) $run->getKey()) {
                        $run->update(['state' => 'superseded']);

                        return ['skip' => true];
                    }
                    if ($run->state === 'running'
                        && $run->direction === PlaylistSyncDirection::Push
                        && is_array($run->checkpoint)) {
                        return [
                            'direction' => PlaylistSyncDirection::Push,
                            'items' => $run->bank_snapshot,
                            'run' => $run,
                        ];
                    }
                    $bank = Playlist::query()->whereKey($sync->playlist_id)->lockForUpdate()->with('items')->firstOrFail();
                    $bankFingerprint = $this->bankFingerprint->handle($bank);
                    $sourceFingerprint = $this->sourceFingerprint->handle($source);
                    $direction = $run->trigger->value === 'activation'
                        ? $this->activationDirection($run, $bankFingerprint, $sourceFingerprint)
                        : $this->determineDirection->handle(
                            $sync->baseline_bank_fingerprint,
                            $sync->baseline_source_fingerprint,
                            $bankFingerprint,
                            $sourceFingerprint,
                        );
                    if (! $direction instanceof PlaylistSyncDirection) {
                        return null;
                    }
                    $bankSnapshot = $bank->items->map(static fn ($item): array => [
                        'position' => $item->position,
                        'provider_item_id' => $item->occurrence_id,
                        'catalog_id' => $item->catalog_id,
                        'catalog_uri' => $item->catalog_uri,
                        'title' => $item->title,
                        'creators' => $item->creators,
                        'album' => $item->album,
                        'duration_milliseconds' => $item->duration_milliseconds,
                        'isrc' => $item->isrc,
                        'is_available' => $item->is_available,
                    ])->values()->all();
                    $run->update([
                        'state' => 'running', 'direction' => $direction,
                        'input_bank_fingerprint' => $bankFingerprint,
                        'input_source_fingerprint' => $sourceFingerprint,
                        'input_provider_revision' => $source->providerRevision,
                        'bank_snapshot' => $bankSnapshot,
                        'source_snapshot' => $source->items,
                    ]);

                    return ['direction' => $direction, 'items' => $bankSnapshot, 'run' => $run];
                });

                if ($prepared === null) {
                    $outcome = SourceSyncFailure::InvalidResponse;

                    return;
                }
                if (($prepared['skip'] ?? false) === true) {
                    $outcome = true;

                    return;
                }
                if ($prepared['direction'] !== PlaylistSyncDirection::Push) {
                    $outcome = [$prepared['direction'], $source];

                    return;
                }
                $writer = $this->writers->writerFor($playlist->source_provider);
                if ($writer === null) {
                    $outcome = SourceSyncFailure::InvalidResponse;

                    return;
                }
                $written = $writer->write($playlist->source_playlist_id, $source, $prepared['items'], $access, $prepared['run']);
                if ($written === SourceSyncFailure::ExternalDrift) {
                    $fresh = $reader->read($playlist->source_playlist_id, $access);
                    $outcome = $fresh instanceof SourcePlaylistSnapshot
                        ? [PlaylistSyncDirection::Pull, $fresh]
                        : $fresh;

                    return;
                }
                $outcome = $written instanceof SourcePlaylistSnapshot
                    ? [PlaylistSyncDirection::Push, $written]
                    : $written;
            },
        );

        if ($outcome === true) {
            return null;
        }
        if (! $accessResult->successful) {
            $failure = match ($accessResult->failure) {
                StreamingAccessFailure::RateLimited => SourceSyncFailure::RateLimited,
                StreamingAccessFailure::TemporarilyUnavailable => SourceSyncFailure::ProviderUnavailable,
                StreamingAccessFailure::ReconnectRequired,
                StreamingAccessFailure::MissingScope,
                StreamingAccessFailure::StaleCredential => SourceSyncFailure::ReconnectRequired,
                StreamingAccessFailure::QuotaExceeded => SourceSyncFailure::QuotaLimited,
                null => null,
            };

            return $this->finishFailure($runId, $failure ?? 'access-failed');
        } elseif ($outcome instanceof SourceSyncFailure || ! is_array($outcome)) {
            return $this->finishFailure(
                $runId,
                $outcome instanceof SourceSyncFailure ? $outcome : 'invalid-run-state',
            );
        } else {
            $this->complete->handle($runId, $outcome[0], $outcome[1]);
        }

        return null;
    }

    private function finishFailure(int $runId, SourceSyncFailure|string $failure): ?SourceSyncFailure
    {
        if ($failure instanceof SourceSyncFailure
            && in_array($failure, [SourceSyncFailure::RateLimited, SourceSyncFailure::ProviderUnavailable], true)) {
            return $failure;
        }

        $this->fail->handle($runId, $failure);

        return $failure instanceof SourceSyncFailure ? $failure : null;
    }

    private function activationDirection(PlaylistSyncRun $run, string $bankFingerprint, string $sourceFingerprint): PlaylistSyncDirection
    {
        if (! hash_equals($run->input_source_fingerprint, $sourceFingerprint)) {
            return PlaylistSyncDirection::Pull;
        }
        if (! hash_equals($run->input_bank_fingerprint, $bankFingerprint)) {
            return $run->direction === PlaylistSyncDirection::Pull
                ? PlaylistSyncDirection::Pull
                : PlaylistSyncDirection::Push;
        }

        return $run->direction;
    }
}
