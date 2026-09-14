<?php

namespace App\Actions\Playlists;

use App\Enums\StreamingProvider;
use App\Models\Playlist;
use App\Models\PlaylistItem;

final class FingerprintPlaylistContent
{
    private const VERSION = 'playlist-content:v1';

    public function handle(Playlist $playlist): string
    {
        $items = $playlist->relationLoaded('items')
            ? $playlist->items
            : $playlist->items()->get();

        $content = [
            'name' => $playlist->name,
            'description' => $playlist->description,
            'source_provider' => $playlist->source_provider instanceof StreamingProvider
                ? $playlist->source_provider->value
                : $playlist->source_provider,
            'source_playlist_id' => $playlist->source_playlist_id,
            'items' => $items
                ->sortBy('position')
                ->values()
                ->map(static fn (PlaylistItem $item, int $ordinal): array => [
                    'ordinal' => $ordinal,
                    'occurrence_id' => $item->occurrence_id,
                    'catalog_id' => $item->catalog_id,
                    'catalog_uri' => $item->catalog_uri,
                    'title' => $item->title,
                    'creators' => array_values($item->creators),
                    'album' => $item->album,
                    'duration_milliseconds' => $item->duration_milliseconds,
                    'isrc' => $item->isrc,
                    'is_available' => $item->is_available,
                ])
                ->all(),
        ];

        $json = json_encode(
            $content,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', self::VERSION."\n".$json);
    }
}
