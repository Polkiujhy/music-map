<?php

namespace App\Integrations\PlaylistSync\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistReader;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SpotifySourcePlaylistReader implements SourcePlaylistReader
{
    private const API_URL = 'https://api.spotify.com/v1';

    public function provider(): StreamingProvider
    {
        return StreamingProvider::Spotify;
    }

    public function read(string $providerPlaylistId, StreamingAccessContext $access): SourcePlaylistSnapshot|SourceSyncFailure
    {
        if ($access->provider !== StreamingProvider::Spotify || $providerPlaylistId === '') {
            return SourceSyncFailure::Unauthorized;
        }

        try {
            $request = Http::withToken($access->accessToken)->acceptJson()->connectTimeout(5)->timeout(10);
            $metadata = $request->get(self::API_URL.'/playlists/'.$providerPlaylistId);

            if (! $metadata->successful()) {
                return $this->failure($metadata);
            }

            $id = $this->string($metadata->json('id'));
            $owner = $this->string($metadata->json('owner.id'));
            $revision = $this->nullableString($metadata->json('snapshot_id'));
            $total = $metadata->json('tracks.total', $metadata->json('items.total'));

            if ($id !== $providerPlaylistId || $owner === null || $revision === false || ! is_int($total) || $total < 0) {
                return SourceSyncFailure::InvalidResponse;
            }

            if ($total > 20) {
                return SourceSyncFailure::OverLimit;
            }

            $items = [];
            $offset = 0;

            do {
                $response = $request->get(self::API_URL.'/playlists/'.$providerPlaylistId.'/items', [
                    'limit' => 20,
                    'offset' => $offset,
                ]);

                if (! $response->successful()) {
                    return $this->failure($response);
                }

                $payload = $response->json();
                if (! is_array($payload) || ! isset($payload['items']) || ! is_array($payload['items']) || ! array_is_list($payload['items'])) {
                    return SourceSyncFailure::InvalidResponse;
                }

                foreach ($payload['items'] as $item) {
                    $normalized = $this->item($item, count($items));
                    if ($normalized instanceof SourceSyncFailure) {
                        return $normalized;
                    }
                    $items[] = $normalized;
                    if (count($items) > 20) {
                        return SourceSyncFailure::OverLimit;
                    }
                }

                $next = $payload['next'] ?? null;
                if ($next !== null && ! is_string($next)) {
                    return SourceSyncFailure::InvalidResponse;
                }
                $offset = count($items);
            } while ($next !== null);

            if (count($items) !== $total) {
                return SourceSyncFailure::InvalidResponse;
            }

            return new SourcePlaylistSnapshot(
                array_column($items, 'catalog_id'),
                $revision === false ? null : $revision,
                $owner,
                array_fill(0, count($items), null),
                $items,
            );
        } catch (Throwable) {
            return SourceSyncFailure::ProviderUnavailable;
        }
    }

    /** @return array<string, mixed>|SourceSyncFailure */
    private function item(mixed $value, int $position): array|SourceSyncFailure
    {
        if (! is_array($value) || ! is_array($value['item'] ?? null)) {
            return SourceSyncFailure::InvalidResponse;
        }

        $item = $value['item'];
        $id = $this->string($item['id'] ?? null);
        $uri = $this->string($item['uri'] ?? null, 512);
        $title = $this->nullableString($item['name'] ?? null);
        $artists = $item['artists'] ?? null;

        if (($item['type'] ?? null) !== 'track' || $id === null || $uri === null || $title === false
            || ! is_array($artists) || ! array_is_list($artists)) {
            return SourceSyncFailure::InvalidResponse;
        }

        $creators = [];
        foreach ($artists as $artist) {
            $name = is_array($artist) ? $this->string($artist['name'] ?? null) : null;
            if ($name === null) {
                return SourceSyncFailure::InvalidResponse;
            }
            $creators[] = $name;
        }

        return [
            'position' => $position,
            'provider_item_id' => null,
            'catalog_id' => $id,
            'catalog_uri' => $uri,
            'title' => $title,
            'creators' => $creators,
            'album' => $this->nullableString($item['album']['name'] ?? null),
            'duration_milliseconds' => is_int($item['duration_ms'] ?? null) ? $item['duration_ms'] : null,
            'isrc' => $this->nullableString($item['external_ids']['isrc'] ?? null, 32),
            'is_available' => ($item['is_playable'] ?? true) !== false,
        ];
    }

    private function failure(Response $response): SourceSyncFailure
    {
        return match (true) {
            $response->status() === 401 => SourceSyncFailure::Unauthorized,
            $response->status() === 403 => SourceSyncFailure::Forbidden,
            $response->status() === 404 => SourceSyncFailure::NotFound,
            $response->status() === 429 => SourceSyncFailure::RateLimited,
            $response->status() >= 500 => SourceSyncFailure::ProviderUnavailable,
            default => SourceSyncFailure::InvalidResponse,
        };
    }

    private function string(mixed $value, int $maximum = 255): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum ? $value : null;
    }

    private function nullableString(mixed $value, int $maximum = 255): string|false|null
    {
        return $value === null ? null : ($this->string($value, $maximum) ?? false);
    }
}
