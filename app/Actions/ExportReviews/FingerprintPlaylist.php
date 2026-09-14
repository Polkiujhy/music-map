<?php

namespace App\Actions\ExportReviews;

use App\Models\Playlist;

final class FingerprintPlaylist
{
    public function handle(Playlist $playlist): string
    {
        $items = $playlist->relationLoaded('items') ? $playlist->items : $playlist->items()->get();

        $content = $items->sortBy('position')->values()->map(fn ($item): array => [
            'position' => (int) $item->position,
            'occurrence_id' => $this->normalize($item->occurrence_id),
            'catalog_id' => $this->normalize($item->catalog_id),
            'catalog_uri' => $this->normalize($item->catalog_uri),
            'title' => $this->normalize($item->title),
            'creators' => array_map($this->normalize(...), $item->creators),
            'album' => $this->normalize($item->album),
            'duration_milliseconds' => $item->duration_milliseconds === null
                ? null
                : (int) $item->duration_milliseconds,
            'isrc' => $this->normalize($item->isrc),
            'is_available' => (bool) $item->is_available,
        ])->values()->all();

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtolower($normalized ?? $value, 'UTF-8');
    }
}
