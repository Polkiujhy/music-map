<?php

namespace Database\Factories;

use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncTrigger;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlaylistSyncRun> */
class PlaylistSyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'playlist_synchronization_id' => PlaylistSynchronization::factory(),
            'operation_id' => (string) Str::uuid(),
            'trigger' => PlaylistSyncTrigger::Manual,
            'direction' => PlaylistSyncDirection::NoOp,
            'state' => 'pending',
            'input_bank_fingerprint' => hash('sha256', 'canary-bank-snapshot'),
            'input_source_fingerprint' => hash('sha256', 'canary-source-snapshot'),
            'input_provider_revision' => 'canary-provider-revision',
            'bank_snapshot' => ['canary-track-a', 'canary-track-b'],
            'source_snapshot' => ['canary-track-a', 'canary-track-b'],
            'desired_fingerprint' => hash('sha256', 'canary-desired-snapshot'),
            'checkpoint' => null,
        ];
    }
}
