<?php

namespace Database\Factories;

use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playlist>
 */
class PlaylistFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'origin' => PlaylistOrigin::Imported,
            'source_provider' => StreamingProvider::YouTube,
            'source_playlist_id' => 'PL-canary-playlist',
            'source_account_id' => null,
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-canary-playlist',
            'provider_revision' => 'canary-revision',
            'name' => 'Canary playlist',
            'description' => 'Non-production fixture data.',
            'provider_metadata_refreshed_at' => now(),
            'imported_at' => now(),
            'bank_content_edited_at' => null,
        ];
    }
}
