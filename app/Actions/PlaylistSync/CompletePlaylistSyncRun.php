<?php

namespace App\Actions\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Models\PlaylistSyncRun;
use Illuminate\Support\Facades\DB;

final readonly class CompletePlaylistSyncRun
{
    public function __construct(
        private ApplySourcePlaylistToBank $applySource,
        private FingerprintPlaylistContent $bankFingerprint,
        private FingerprintSourcePlaylist $sourceFingerprint,
        private DispatchPlaylistSynchronization $dispatch,
    ) {}

    public function handle(int $runId, PlaylistSyncDirection $direction, SourcePlaylistSnapshot $source): void
    {
        $retry = DB::transaction(function () use ($runId, $direction, $source): ?array {
            $run = PlaylistSyncRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! in_array($run->state, ['pending', 'running'], true)) {
                return null;
            }
            $sync = $run->synchronization()->lockForUpdate()->firstOrFail();
            if ($sync->status !== PlaylistSyncStatus::Enabled || $sync->streaming_account_id === null) {
                $run->update([
                    'state' => $sync->status === PlaylistSyncStatus::Disabled
                        ? 'cancelled'
                        : 'superseded',
                ]);

                return null;
            }
            $playlist = $direction === PlaylistSyncDirection::Pull
                ? $this->applySource->handle((int) $sync->playlist_id, $source)
                : $sync->playlist()->lockForUpdate()->with('items')->firstOrFail();
            $currentBankFingerprint = $this->bankFingerprint->handle($playlist);
            $bankChangedDuringRun = $direction !== PlaylistSyncDirection::Pull
                && ! hash_equals($run->input_bank_fingerprint, $currentBankFingerprint);

            if ($direction !== PlaylistSyncDirection::Pull) {
                $playlist->update([
                    'provider_revision' => $source->providerRevision,
                    'provider_metadata_refreshed_at' => now(),
                    'bank_content_edited_at' => $direction === PlaylistSyncDirection::Push && ! $bankChangedDuringRun
                        ? null
                        : $playlist->bank_content_edited_at,
                ]);
                $playlist->refresh()->load('items');
            }

            $sync->update([
                'status' => PlaylistSyncStatus::Enabled,
                'baseline_bank_fingerprint' => $bankChangedDuringRun
                    ? $run->input_bank_fingerprint
                    : $this->bankFingerprint->handle($playlist),
                'baseline_source_fingerprint' => $this->sourceFingerprint->handle($source),
                'baseline_provider_revision' => $source->providerRevision,
                'last_checked_at' => now(),
                'last_succeeded_at' => now(),
                'last_outcome' => match ($direction) {
                    PlaylistSyncDirection::NoOp => PlaylistSyncOutcome::NoOp,
                    PlaylistSyncDirection::Pull => PlaylistSyncOutcome::Pulled,
                    PlaylistSyncDirection::Push => PlaylistSyncOutcome::Pushed,
                },
                'last_failure_code' => null,
            ]);
            $run->update(['direction' => $direction, 'state' => 'completed']);

            return $bankChangedDuringRun
                ? [
                    'synchronization_id' => (int) $sync->getKey(),
                    'trigger' => $run->trigger,
                ]
                : null;
        });

        if ($retry !== null) {
            $this->dispatch->handle($retry['synchronization_id'], $retry['trigger']);
        }
    }
}
