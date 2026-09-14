<?php

namespace App\Integrations\PlaylistImport\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Contracts\PlaylistSourceReader;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\ProviderImportFailureMapper;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SpotifyPlaylistReader implements PlaylistSourceReader
{
    public const REQUIRED_SCOPES = [
        'playlist-read-collaborative',
        'playlist-read-private',
    ];

    private const API_URL = 'https://api.spotify.com/v1';

    public function read(
        PlaylistReference $reference,
        ?StreamingAccessContext $access = null,
    ): PlaylistSnapshot|ImportFailureCode {
        if ($reference->provider !== StreamingProvider::Spotify) {
            return ImportFailureCode::UnsupportedProvider;
        }

        if ($access === null) {
            return ImportFailureCode::LinkedAccountRequired;
        }

        if ($access->provider !== StreamingProvider::Spotify || $access->providerAccountId === '') {
            return ImportFailureCode::ReauthorizationRequired;
        }

        try {
            $request = Http::withToken($access->accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10);
            $metadata = $request->get(self::API_URL.'/playlists/'.$reference->providerPlaylistId);

            if (! $metadata->successful()) {
                return ProviderImportFailureMapper::response($metadata);
            }

            // In Spotify Development Mode, this endpoint succeeds only for a
            // playlist the linked account owns or collaborates on.
            $items = $request->get(self::API_URL.'/playlists/'.$reference->providerPlaylistId.'/items', [
                'limit' => 21,
                'offset' => 0,
            ]);

            if (! $items->successful()) {
                return ProviderImportFailureMapper::response($items);
            }
        } catch (Throwable) {
            return ProviderImportFailureMapper::transport();
        }

        return $this->snapshot($reference, $access, $metadata, $items);
    }

    private function snapshot(
        PlaylistReference $reference,
        StreamingAccessContext $access,
        Response $metadataResponse,
        Response $itemsResponse,
    ): PlaylistSnapshot|ImportFailureCode {
        $metadata = $metadataResponse->json();
        $payload = $itemsResponse->json();

        if (! is_array($metadata)
            || ! is_array($payload)
            || ! isset($payload['items'])
            || ! is_array($payload['items'])
            || ! array_is_list($payload['items'])) {
            return ImportFailureCode::InvalidResponse;
        }

        $id = $this->boundedString($metadata['id'] ?? null);
        $name = $this->nullableString($metadata['name'] ?? null, 255);
        $description = $this->nullableString($metadata['description'] ?? null, 65535);
        $revision = $this->nullableString($metadata['snapshot_id'] ?? null, 255);
        $metadataTotal = $metadata['items']['total'] ?? null;
        $itemsTotal = $payload['total'] ?? null;

        if ($id !== $reference->providerPlaylistId
            || $name === false
            || $name === null
            || $description === false
            || $revision === false
            || ! is_int($metadataTotal)
            || $metadataTotal < 0
            || ! is_int($itemsTotal)
            || $itemsTotal < 0) {
            return ImportFailureCode::InvalidResponse;
        }

        if ($metadataTotal > 20
            || $itemsTotal > 20
            || count($payload['items']) > 20
            || ($payload['next'] ?? null) !== null) {
            return ImportFailureCode::TooManyItems;
        }

        if ($metadataTotal !== count($payload['items'])
            || $itemsTotal !== count($payload['items'])) {
            return ImportFailureCode::InvalidResponse;
        }

        $items = [];

        foreach ($payload['items'] as $position => $item) {
            $normalized = $this->item($item, $position);

            if ($normalized instanceof ImportFailureCode) {
                return $normalized;
            }

            $items[] = $normalized;
        }

        return new PlaylistSnapshot(
            StreamingProvider::Spotify,
            $reference->providerPlaylistId,
            $access->providerAccountId,
            $reference->canonicalUrl,
            $revision,
            $name,
            $description,
            now()->toImmutable(),
            $items,
        );
    }

    private function item(mixed $item, int $position): PlaylistItemSnapshot|ImportFailureCode
    {
        if (! is_array($item)) {
            return ImportFailureCode::InvalidResponse;
        }

        if (($item['is_local'] ?? false) === true) {
            return ImportFailureCode::UnsupportedItem;
        }

        $track = $item['item'] ?? null;

        if ($track === null) {
            return new PlaylistItemSnapshot(
                null,
                null,
                null,
                null,
                [],
                null,
                null,
                null,
                $position,
                false,
            );
        }

        if (! is_array($track)) {
            return ImportFailureCode::InvalidResponse;
        }

        if (($track['type'] ?? null) !== 'track' || ($track['is_local'] ?? false) === true) {
            return ImportFailureCode::UnsupportedItem;
        }

        $catalogId = $this->boundedString($track['id'] ?? null);
        $catalogUri = $this->boundedString($track['uri'] ?? null, 512);
        $title = $this->boundedString($track['name'] ?? null);
        $album = $this->nullableString($track['album']['name'] ?? null, 255);
        $duration = $track['duration_ms'] ?? null;
        $isrc = $this->nullableString($track['external_ids']['isrc'] ?? null, 32);
        $artists = $track['artists'] ?? null;

        if ($catalogId === null
            || $catalogUri === null
            || $title === null
            || $album === false
            || ! is_int($duration)
            || $duration < 0
            || $isrc === false
            || ! is_array($artists)
            || ! array_is_list($artists)) {
            return ImportFailureCode::InvalidResponse;
        }

        $creators = [];

        foreach ($artists as $artist) {
            $creator = is_array($artist)
                ? $this->boundedString($artist['name'] ?? null)
                : null;

            if ($creator === null) {
                return ImportFailureCode::InvalidResponse;
            }

            $creators[] = $creator;
        }

        $available = ($track['is_playable'] ?? true) !== false;

        return new PlaylistItemSnapshot(
            null,
            $available ? $catalogId : null,
            $available ? $catalogUri : null,
            $available ? $title : null,
            $available ? $creators : [],
            $available ? $album : null,
            $available ? $duration : null,
            $available ? $isrc : null,
            $position,
            $available,
        );
    }

    private function boundedString(mixed $value, int $maximum = 255): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum ? $value : null;
    }

    private function nullableString(mixed $value, int $maximum): string|false|null
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) && strlen($value) <= $maximum ? $value : false;
    }
}
