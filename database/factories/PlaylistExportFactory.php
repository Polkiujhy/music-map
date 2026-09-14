<?php

namespace Database\Factories;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlaylistExport> */
class PlaylistExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_playlist_id' => Playlist::factory(),
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'streaming_account_id' => null,
            'target_account_id' => 'canary-managed-account-'.fake()->unique()->uuid(),
            'target_market' => 'GB',
            'target_generation' => 1,
            'target_playlist_id' => null,
        ];
    }
}
