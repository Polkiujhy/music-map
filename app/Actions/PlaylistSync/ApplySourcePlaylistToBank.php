<?php

namespace App\Actions\PlaylistSync;

use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;

final class ApplySourcePlaylistToBank
{
    public function handle(int $playlistId, SourcePlaylistSnapshot $source): Playlist
    {
        return DB::transaction(function () use ($playlistId, $source): Playlist {
            $playlist = Playlist::query()->whereKey($playlistId)->lockForUpdate()->firstOrFail();
            $streamingAccountId = $playlist->streaming_account_id;

            $playlist->items()->delete();
            foreach ($source->items as $position => $item) {
                $playlist->items()->create([
                    'position' => $position,
                    'occurrence_id' => $item['provider_item_id'] ?? null,
                    'catalog_id' => $item['catalog_id'],
                    'catalog_uri' => $item['catalog_uri'] ?? null,
                    'title' => $item['title'] ?? null,
                    'creators' => $item['creators'] ?? [],
                    'album' => $item['album'] ?? null,
                    'duration_milliseconds' => $item['duration_milliseconds'] ?? null,
                    'isrc' => $item['isrc'] ?? null,
                    'is_available' => $item['is_available'] ?? true,
                ]);
            }
            $playlist->update([
                'streaming_account_id' => $streamingAccountId,
                'provider_revision' => $source->providerRevision,
                'provider_metadata_refreshed_at' => now(),
                'bank_content_edited_at' => null,
            ]);

            return $playlist->refresh()->load('items');
        });
    }
}
