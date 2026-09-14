<?php

namespace Database\Factories;

use App\Enums\PlaylistSyncStatus;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlaylistSynchronization> */
class PlaylistSynchronizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'streaming_account_id' => null,
            'status' => PlaylistSyncStatus::PendingConfirmation,
            'automatic_enabled' => false,
            'baseline_bank_fingerprint' => null,
            'baseline_source_fingerprint' => null,
            'baseline_provider_revision' => null,
            'last_checked_at' => null,
            'last_succeeded_at' => null,
            'next_check_at' => null,
            'last_outcome' => null,
            'last_failure_code' => null,
        ];
    }
}
