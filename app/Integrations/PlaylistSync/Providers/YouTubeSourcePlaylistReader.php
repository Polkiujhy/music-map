<?php

namespace App\Integrations\PlaylistSync\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistReader;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\YouTubeSourceSyncFailureMapper;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class YouTubeSourcePlaylistReader implements SourcePlaylistReader
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    public function provider(): StreamingProvider
    {
        return StreamingProvider::YouTube;
    }

    public function read(string $providerPlaylistId, StreamingAccessContext $access): SourcePlaylistSnapshot|SourceSyncFailure
    {
        if ($access->provider !== StreamingProvider::YouTube || $providerPlaylistId === '') {
            return SourceSyncFailure::Unauthorized;
        }

        try {
            $request = Http::withToken($access->accessToken)->acceptJson()->connectTimeout(5)->timeout(10);
            $metadata = $request->get(self::API_URL.'/playlists', [
                'part' => 'snippet,contentDetails',
                'id' => $providerPlaylistId,
                'maxResults' => 1,
            ]);

            if (! $metadata->successful()) {
                return $this->failure($metadata);
            }

            $sources = $metadata->json('items');
            if (! is_array($sources) || ! array_is_list($sources)) {
                return SourceSyncFailure::InvalidResponse;
            }
            if ($sources === []) {
                return SourceSyncFailure::NotFound;
            }
            if (count($sources) !== 1 || ! is_array($sources[0])) {
                return SourceSyncFailure::InvalidResponse;
            }

            $source = $sources[0];
            $id = $this->string($source['id'] ?? null);
            $owner = $this->string($source['snippet']['channelId'] ?? null);
            $revision = $this->nullableString($source['etag'] ?? null);
            $total = $source['contentDetails']['itemCount'] ?? null;

            if ($id !== $providerPlaylistId || $owner === null || $revision === false || ! is_int($total) || $total < 0) {
                return SourceSyncFailure::InvalidResponse;
            }
            if ($total > 20) {
                return SourceSyncFailure::OverLimit;
            }

            $items = [];
            $pageToken = null;
            do {
                $query = [
                    'part' => 'id,snippet,status',
                    'playlistId' => $providerPlaylistId,
                    'maxResults' => 20,
                ];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }

                $response = $request->get(self::API_URL.'/playlistItems', $query);
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

                $pageToken = $payload['nextPageToken'] ?? null;
                if ($pageToken !== null && (! is_string($pageToken) || $pageToken === '')) {
                    return SourceSyncFailure::InvalidResponse;
                }
            } while ($pageToken !== null);

            if (count($items) !== $total) {
                return SourceSyncFailure::InvalidResponse;
            }

            return new SourcePlaylistSnapshot(
                array_column($items, 'catalog_id'),
                $revision === false ? null : $revision,
                $owner,
                array_column($items, 'provider_item_id'),
                $items,
            );
        } catch (Throwable) {
            return SourceSyncFailure::ProviderUnavailable;
        }
    }

    /** @return array<string, mixed>|SourceSyncFailure */
    private function item(mixed $value, int $position): array|SourceSyncFailure
    {
        if (! is_array($value)) {
            return SourceSyncFailure::InvalidResponse;
        }
        $providerItemId = $this->string($value['id'] ?? null);
        $actualPosition = $value['snippet']['position'] ?? null;
        $kind = $value['snippet']['resourceId']['kind'] ?? null;
        $videoId = $this->nullableString($value['snippet']['resourceId']['videoId'] ?? null);
        $title = $this->nullableString($value['snippet']['title'] ?? null);
        $creator = $this->nullableString($value['snippet']['videoOwnerChannelTitle'] ?? null);
        $privacyStatus = $this->nullableString($value['status']['privacyStatus'] ?? null, 32);

        if ($providerItemId === null || $actualPosition !== $position || $videoId === false
            || $title === false || $creator === false || $privacyStatus === false) {
            return SourceSyncFailure::InvalidResponse;
        }
        if ($kind !== null && $kind !== 'youtube#video') {
            return SourceSyncFailure::UnsupportedItem;
        }

        $unavailable = $videoId === null
            || in_array($title, ['Deleted video', 'Private video'], true)
            || $privacyStatus === 'private';

        if (! $unavailable && $title === null) {
            return SourceSyncFailure::InvalidResponse;
        }

        return [
            'position' => $position,
            'provider_item_id' => $providerItemId,
            'catalog_id' => $unavailable ? null : $videoId,
            'catalog_uri' => $unavailable ? null : 'https://www.youtube.com/watch?v='.$videoId,
            'title' => $unavailable ? null : $title,
            'creators' => $unavailable || $creator === null ? [] : [$creator],
            'album' => null,
            'duration_milliseconds' => null,
            'isrc' => null,
            'is_available' => ! $unavailable,
        ];
    }

    private function failure(Response $response): SourceSyncFailure
    {
        return YouTubeSourceSyncFailureMapper::response($response);
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
