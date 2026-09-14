<?php

namespace App\Actions\PlaylistSync;

use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Jobs\RunPlaylistSynchronization;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DispatchPlaylistSynchronization
{
    public function handle(int $synchronizationId, PlaylistSyncTrigger $trigger): ?PlaylistSyncRun
    {
        $run = DB::transaction(function () use ($synchronizationId, $trigger): ?PlaylistSyncRun {
            $sync = PlaylistSynchronization::query()
                ->whereKey($synchronizationId)
                ->lockForUpdate()
                ->with('playlist.items')
                ->first();

            if (! $sync instanceof PlaylistSynchronization
                || $sync->status !== PlaylistSyncStatus::Enabled
                || $sync->streaming_account_id === null) {
                return null;
            }

            $active = $sync->runs()
                ->whereIn('state', ['pending', 'running'])
                ->orderBy('id')
                ->first();

            if ($active instanceof PlaylistSyncRun) {
                return null;
            }

            $bankSnapshot = $sync->playlist->items->map(static fn ($item): array => [
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

            return $sync->runs()->create([
                'operation_id' => (string) Str::uuid(),
                'trigger' => $trigger,
                'direction' => PlaylistSyncDirection::NoOp,
                'state' => 'pending',
                'input_bank_fingerprint' => $sync->baseline_bank_fingerprint,
                'input_source_fingerprint' => $sync->baseline_source_fingerprint,
                'input_provider_revision' => $sync->baseline_provider_revision,
                'bank_snapshot' => $bankSnapshot,
                'source_snapshot' => [],
                'desired_fingerprint' => null,
                'checkpoint' => null,
            ]);
        });

        if ($run instanceof PlaylistSyncRun) {
            $this->dispatchRun($run);
        }

        return $run;
    }

    public function dispatchRun(PlaylistSyncRun $run): void
    {
        RunPlaylistSynchronization::dispatch((int) $run->getKey())->afterCommit();
    }
}
