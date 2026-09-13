<?php

namespace App\Actions\Playlists;

use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReplaceImportedPlaylist
{
    public function handle(User $user, PlaylistSnapshot $snapshot): Playlist
    {
        return DB::transaction(function () use ($user, $snapshot): Playlist {
            $identity = [
                'user_id' => $user->getKey(),
                'source_provider' => $snapshot->provider->value,
                'source_playlist_id' => $snapshot->providerPlaylistId,
            ];

            DB::table('playlists')->upsert(
                [[
                    ...$identity,
                    ...$this->playlistAttributes($snapshot),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'source_provider', 'source_playlist_id'],
                ['updated_at'],
            );

            $playlist = $user->playlists()
                ->where('source_provider', $snapshot->provider->value)
                ->where('source_playlist_id', $snapshot->providerPlaylistId)
                ->lockForUpdate()
                ->firstOrFail();

            $playlist->update($this->playlistAttributes($snapshot));
            $playlist->items()->delete();
            $playlist->items()->createMany(array_map(
                fn (PlaylistItemSnapshot $item): array => [
                    'position' => $item->position,
                    'occurrence_id' => $item->occurrenceId,
                    'catalog_id' => $item->catalogId,
                    'catalog_uri' => $item->catalogUri,
                    'title' => $item->title,
                    'creators' => $item->creators,
                    'album' => $item->album,
                    'duration_milliseconds' => $item->durationMilliseconds,
                    'isrc' => $item->isrc,
                    'is_available' => $item->isAvailable,
                ],
                $snapshot->items,
            ));

            return $playlist->refresh()->load('items');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function playlistAttributes(PlaylistSnapshot $snapshot): array
    {
        return [
            'source_account_id' => $snapshot->sourceAccountId,
            'canonical_source_url' => $snapshot->canonicalUrl,
            'provider_revision' => $snapshot->providerRevision,
            'name' => $snapshot->name,
            'description' => $snapshot->description,
            'provider_metadata_refreshed_at' => $snapshot->providerMetadataRefreshedAt,
            'imported_at' => now(),
        ];
    }
}
