<?php

namespace App\Console\Commands;

use App\Actions\PlaylistSync\DispatchPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Models\PlaylistSynchronization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchDuePlaylistSynchronizations extends Command
{
    protected $signature = 'playlist-sync:dispatch-due';

    protected $description = 'Queue due automatic source-playlist synchronizations';

    public function handle(DispatchPlaylistSynchronization $dispatch): int
    {
        $limit = max(1, (int) config('playlist-sync.dispatch_batch_size', 50));
        $dueIds = PlaylistSynchronization::query()
            ->where('status', PlaylistSyncStatus::Enabled->value)
            ->where('automatic_enabled', true)
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($dueIds as $id) {
            DB::transaction(function () use ($dispatch, $id): void {
                $sync = PlaylistSynchronization::query()->whereKey($id)->lockForUpdate()->first();
                if (! $sync instanceof PlaylistSynchronization
                    || $sync->status !== PlaylistSyncStatus::Enabled
                    || ! $sync->automatic_enabled
                    || $sync->next_check_at === null
                    || $sync->next_check_at->isFuture()) {
                    return;
                }

                $sync->update(['next_check_at' => now()->addMinutes($this->jitterMinutes((int) $sync->getKey()))]);
                $dispatch->handle((int) $sync->getKey(), PlaylistSyncTrigger::Automatic);

            });
        }

        return self::SUCCESS;
    }

    private function jitterMinutes(int $synchronizationId): int
    {
        $maximum = min(240, max(1, (int) config('playlist-sync.maximum_check_interval_minutes', 240)));
        $bucket = now()->format('Y-m-d-H');
        $hash = hexdec(substr(hash('sha256', $synchronizationId.'|'.$bucket), 0, 8));

        return 1 + ($hash % $maximum);
    }
}
