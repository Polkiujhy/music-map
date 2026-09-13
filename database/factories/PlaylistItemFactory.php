<?php

namespace Database\Factories;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaylistItem>
 */
class PlaylistItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'playlist_id' => Playlist::factory(),
            'position' => 0,
            'occurrence_id' => 'canary-occurrence',
            'catalog_id' => 'canary-catalog-id',
            'catalog_uri' => 'canary:catalog:item',
            'title' => 'Canary item',
            'creators' => ['Canary creator'],
            'album' => 'Canary album',
            'duration_milliseconds' => 1000,
            'isrc' => 'CANARY000001',
            'is_available' => true,
        ];
    }
}
