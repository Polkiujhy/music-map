<?php

namespace App\Console\Commands;

use App\Models\PlaylistSyncRun;
use Illuminate\Console\Command;

final class PrunePlaylistSyncRuns extends Command
{
    protected $signature = 'playlist-sync:prune-runs';

    protected $description = 'Prune old terminal source-playlist synchronization runs';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) config('playlist-sync.run_retention_days', 14)));
        $batchSize = max(1, (int) config('playlist-sync.prune_batch_size', 500));

        do {
            $ids = PlaylistSyncRun::query()
                ->whereIn('state', ['completed', 'failed', 'superseded', 'cancelled'])
                ->where('updated_at', '<', $before)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                PlaylistSyncRun::query()->whereIn('id', $ids->all())->delete();
            }
        } while ($ids->count() === $batchSize);

        return self::SUCCESS;
    }
}
