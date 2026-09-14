<?php

namespace Database\Factories;

use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlaylistExportTargetAttempt> */
class PlaylistExportTargetAttemptFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (PlaylistExportTargetAttempt $attempt): void {
            if ($attempt->playlist_export_id === null) {
                return;
            }

            $playlistExport = PlaylistExport::query()->findOrFail($attempt->playlist_export_id);
            $attempt->target_provider = $playlistExport->target_provider;
            $attempt->target_account_id = $playlistExport->target_account_id;
        });
    }

    public function definition(): array
    {
        return [
            'playlist_export_id' => PlaylistExport::factory(),
            'target_provider' => null,
            'target_account_id' => null,
            'generation' => 1,
            'marker' => (string) Str::uuid(),
            'status' => PlaylistExportTargetAttempt::STATUS_PENDING,
            'create_started_at' => null,
            'create_completed_at' => null,
            'provider_playlist_id' => null,
            'canonical_url' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => PlaylistExportTargetAttempt::STATUS_RESOLVED,
            'create_started_at' => now()->subSecond(),
            'create_completed_at' => now(),
            'provider_playlist_id' => 'canary-provider-playlist-'.fake()->unique()->uuid(),
            'canonical_url' => 'https://example.test/playlists/'.fake()->unique()->uuid(),
        ]);
    }
}
