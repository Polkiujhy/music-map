<?php

namespace App\Actions\PlaylistSync;

use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Models\PlaylistSyncRun;
use Illuminate\Support\Facades\DB;

final class FailPlaylistSyncRun
{
    public function handle(int $runId, SourceSyncFailure|string $failure): void
    {
        DB::transaction(function () use ($runId, $failure): void {
            $run = PlaylistSyncRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (in_array($run->state, ['completed', 'failed', 'superseded', 'cancelled'], true)) {
                return;
            }
            $code = $failure instanceof SourceSyncFailure ? $failure->value : $failure;
            $run->update(['state' => 'failed']);
            $run->synchronization()->lockForUpdate()->firstOrFail()->update([
                'status' => PlaylistSyncStatus::Attention,
                'automatic_enabled' => false,
                'last_checked_at' => now(),
                'last_outcome' => PlaylistSyncOutcome::Failed,
                'last_failure_code' => mb_substr($code, 0, 64),
            ]);
        });
    }
}
