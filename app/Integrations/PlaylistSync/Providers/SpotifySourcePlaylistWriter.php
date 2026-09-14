<?php

namespace App\Integrations\PlaylistSync\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistWriter;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\PlaylistSyncRun;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SpotifySourcePlaylistWriter implements SourcePlaylistWriter
{
    private const API_URL = 'https://api.spotify.com/v1';

    public function __construct(private readonly SpotifySourcePlaylistReader $reader) {}

    public function provider(): StreamingProvider
    {
        return StreamingProvider::Spotify;
    }

    public function write(string $providerPlaylistId, SourcePlaylistSnapshot $current, array $desiredItems, StreamingAccessContext $access, ?PlaylistSyncRun $run = null): SourcePlaylistSnapshot|SourceSyncFailure
    {
        if ($access->provider !== StreamingProvider::Spotify || $providerPlaylistId === '' || count($desiredItems) > 20) {
            return SourceSyncFailure::InvalidResponse;
        }

        $writableItems = [];
        foreach ($desiredItems as $item) {
            // Spotify does not expose an addressable URI for unavailable positions.
            // Keep those positions in the bank and replace the source with its writable projection.
            if (($item['is_available'] ?? true) === false && ($item['catalog_uri'] ?? null) === null) {
                continue;
            }

            $uri = $item['catalog_uri'] ?? null;
            $identifier = $item['catalog_id'] ?? null;
            if (! is_string($uri) || ! str_starts_with($uri, 'spotify:track:')
                || ! is_string($identifier) || $identifier === '') {
                return SourceSyncFailure::InvalidResponse;
            }
            $writableItems[] = $item;
        }

        $uris = array_column($writableItems, 'catalog_uri');

        try {
            $response = Http::withToken($access->accessToken)->acceptJson()->connectTimeout(5)->timeout(10)
                ->put(self::API_URL.'/playlists/'.$providerPlaylistId.'/items', ['uris' => $uris]);
            if (! $response->successful()) {
                return $this->failure($response);
            }
            $revision = $response->json('snapshot_id');
            if (! is_string($revision) || $revision === '') {
                return SourceSyncFailure::InvalidResponse;
            }

            $confirmed = $this->reader->read($providerPlaylistId, $access);
            if (! $confirmed instanceof SourcePlaylistSnapshot
                || $confirmed->normalizedItemIdentifiers() !== $this->identifiers($writableItems)
                || $confirmed->providerRevision !== $revision) {
                return $confirmed instanceof SourceSyncFailure ? $confirmed : SourceSyncFailure::InvalidResponse;
            }

            return $confirmed;
        } catch (Throwable) {
            return SourceSyncFailure::ProviderUnavailable;
        }
    }

    /** @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private function identifiers(array $items): array
    {
        return array_map(static fn (array $item): string => (string) ($item['catalog_id'] ?? ''), $items);
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
}
