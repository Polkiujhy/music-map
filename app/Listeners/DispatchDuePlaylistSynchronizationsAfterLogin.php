<?php

namespace App\Listeners;

use App\Actions\PlaylistSync\DispatchPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Models\PlaylistSynchronization;
use Illuminate\Auth\Events\Login;

final readonly class DispatchDuePlaylistSynchronizationsAfterLogin
{
    public function __construct(
        private DispatchPlaylistSynchronization $dispatch,
    ) {}

    public function handle(Login $event): void
    {
        $threshold = now()->subMinutes(max(15, (int) config('playlist-sync.login_check_threshold_minutes', 15)));
        $limit = max(1, (int) config('playlist-sync.dispatch_batch_size', 50));

        PlaylistSynchronization::query()
            ->where('status', PlaylistSyncStatus::Enabled->value)
            ->whereHas('playlist', fn ($query) => $query->where('user_id', $event->user->getAuthIdentifier()))
            ->where(function ($query) use ($threshold): void {
                $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $threshold);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->each(fn (int $id) => $this->dispatch->handle($id, PlaylistSyncTrigger::Login));
    }
}
