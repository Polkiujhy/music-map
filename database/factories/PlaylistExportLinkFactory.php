<?php

namespace Database\Factories;

use App\Enums\ExportDestinationType;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlaylistExportLink> */
class PlaylistExportLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source_playlist_id' => Playlist::factory()->state(['role' => PlaylistRole::Source]),
            'target_playlist_id' => Playlist::factory()->state([
                'role' => PlaylistRole::ExportTarget,
                'source_playlist_id' => fake()->unique()->uuid(),
            ]),
            'provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => fake()->uuid(),
            'active_key' => null,
            'retired_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (PlaylistExportLink $link): void {
            $source = $link->sourcePlaylist()->firstOrFail();
            $target = $link->targetPlaylist()->firstOrFail();
            $target->forceFill(['user_id' => $source->user_id])->save();
            $link->user_id = $source->user_id;

            if ($link->active_key === null && $link->retired_at === null && $link->source_playlist_id !== null) {
                $link->active_key = PlaylistExportLink::activeKey(
                    (int) $link->source_playlist_id,
                    $link->provider,
                    $link->target_account_id,
                );
            }
        });
    }
}
