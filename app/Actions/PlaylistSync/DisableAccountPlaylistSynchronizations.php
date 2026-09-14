<?php

namespace App\Actions\PlaylistSync;

use App\Enums\PlaylistSyncStatus;
use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;

final class DisableAccountPlaylistSynchronizations implements DisableDependentStreamingSynchronizations
{
    public function handle(StreamingAccount $account): void
    {
        $ids = PlaylistSynchronization::query()
            ->where('streaming_account_id', $account->getKey())
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        PlaylistSyncRun::query()
            ->whereIn('playlist_synchronization_id', $ids)
            ->whereIn('state', ['pending', 'running'])
            ->update(['state' => 'cancelled', 'updated_at' => now()]);

        PlaylistSynchronization::query()
            ->whereIn('id', $ids->all())
            ->update([
                'status' => PlaylistSyncStatus::Disabled->value,
                'automatic_enabled' => false,
                'next_check_at' => null,
                'updated_at' => now(),
            ]);
    }
}
