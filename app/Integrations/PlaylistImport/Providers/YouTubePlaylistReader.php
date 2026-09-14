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

final readonly class YouTubePlaylistReader implements PlaylistSourceReader
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private ?string $apiKey = null,
    ) {}

    public function read(
        PlaylistReference $reference,
        ?StreamingAccessContext $access = null,
    ): PlaylistSnapshot|ImportFailureCode {
        if ($reference->provider !== StreamingProvider::YouTube || $access !== null) {
            return ImportFailureCode::UnsupportedProvider;
        }

        $apiKey = $this->apiKey ?? config('services.playlist_import.youtube.api_key');

        if (! is_string($apiKey) || $apiKey === '' || str_starts_with($apiKey, '__REQUIRED_')) {
            return ImportFailureCode::ProviderUnavailable;
        }

        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(10);
            $metadata = $request->get(self::API_URL.'/playlists', [
                'part' => 'snippet,contentDetails',
                'id' => $reference->providerPlaylistId,
                'key' => $apiKey,
            ]);

            if (! $metadata->successful()) {
                return ProviderImportFailureMapper::response($metadata);
            }

            $items = $request->get(self::API_URL.'/playlistItems', [
                'part' => 'id,snippet,status',
                'playlistId' => $reference->providerPlaylistId,
                'maxResults' => 21,
                'key' => $apiKey,
            ]);

            if (! $items->successful()) {
                return ProviderImportFailureMapper::response($items);
            }
        } catch (Throwable) {
            return ProviderImportFailureMapper::transport();
        }

        return $this->snapshot($reference, $metadata, $items);
    }

    private function snapshot(
        PlaylistReference $reference,
        Response $metadataResponse,
        Response $itemsResponse,
    ): PlaylistSnapshot|ImportFailureCode {
        $metadata = $metadataResponse->json();
        $itemPayload = $itemsResponse->json();

        if (! is_array($metadata)
            || ! isset($metadata['items'])
            || ! is_array($metadata['items'])
            || ! array_is_list($metadata['items'])
            || ! is_array($itemPayload)
            || ! isset($itemPayload['items'])
            || ! is_array($itemPayload['items'])
            || ! array_is_list($itemPayload['items'])) {
            return ImportFailureCode::InvalidResponse;
        }

        if ($metadata['items'] === []) {
            return ImportFailureCode::PlaylistNotFound;
        }

        if (count($metadata['items']) !== 1 || ! is_array($metadata['items'][0])) {
            return ImportFailureCode::InvalidResponse;
        }

        $source = $metadata['items'][0];
        $sourceId = $this->boundedString($source['id'] ?? null);
        $title = $this->nullableString($source['snippet']['title'] ?? null, 255);
        $description = $this->nullableString($source['snippet']['description'] ?? null, 65535);
        $accountId = $this->nullableString($source['snippet']['channelId'] ?? null, 255);
        $revision = $this->nullableString($source['etag'] ?? null, 255);
        $declaredCount = $source['contentDetails']['itemCount'] ?? null;
        $totalResults = $itemPayload['pageInfo']['totalResults'] ?? null;

        if ($sourceId !== $reference->providerPlaylistId
            || $title === false
            || $title === null
            || $description === false
            || $accountId === false
            || $accountId === null
            || $revision === false
            || ! is_int($declaredCount)
            || $declaredCount < 0
            || ! is_int($totalResults)
            || $totalResults < 0) {
            return ImportFailureCode::InvalidResponse;
        }

        if ($declaredCount > 20
            || $totalResults > 20
            || count($itemPayload['items']) > 20
            || array_key_exists('nextPageToken', $itemPayload)) {
            return ImportFailureCode::TooManyItems;
        }

        if ($declaredCount !== count($itemPayload['items'])
            || $totalResults !== count($itemPayload['items'])) {
            return ImportFailureCode::InvalidResponse;
        }

        $items = [];

        foreach ($itemPayload['items'] as $position => $item) {
            $normalized = $this->item($item, $position);

            if ($normalized instanceof ImportFailureCode) {
                return $normalized;
            }

            $items[] = $normalized;
        }

        return new PlaylistSnapshot(
            StreamingProvider::YouTube,
            $reference->providerPlaylistId,
            $accountId,
            $reference->canonicalUrl,
            $revision,
            $title,
            $description,
            now()->toImmutable(),
            $items,
        );
    }

    private function item(mixed $item, int $expectedPosition): PlaylistItemSnapshot|ImportFailureCode
    {
        if (! is_array($item)) {
            return ImportFailureCode::InvalidResponse;
        }

        $occurrenceId = $this->boundedString($item['id'] ?? null);
        $position = $item['snippet']['position'] ?? null;
        $kind = $item['snippet']['resourceId']['kind'] ?? null;
        $videoId = $this->nullableString($item['snippet']['resourceId']['videoId'] ?? null, 255);
        $title = $this->nullableString($item['snippet']['title'] ?? null, 255);
        $creator = $this->nullableString($item['snippet']['videoOwnerChannelTitle'] ?? null, 255);
        $privacyStatus = $this->nullableString($item['status']['privacyStatus'] ?? null, 32);

        if ($occurrenceId === null
            || ! is_int($position)
            || $position !== $expectedPosition
            || $videoId === false
            || $title === false
            || $creator === false
            || $privacyStatus === false) {
            return ImportFailureCode::InvalidResponse;
        }

        if ($kind !== 'youtube#video') {
            return ImportFailureCode::UnsupportedItem;
        }

        $unavailable = $videoId === null
            || in_array($title, ['Deleted video', 'Private video'], true)
            || $privacyStatus === 'private';

        if (! $unavailable && $title === null) {
            return ImportFailureCode::InvalidResponse;
        }

        return new PlaylistItemSnapshot(
            $occurrenceId,
            $unavailable ? null : $videoId,
            $unavailable ? null : 'https://www.youtube.com/watch?v='.$videoId,
            $unavailable ? null : $title,
            $unavailable || $creator === null ? [] : [$creator],
            null,
            null,
            null,
            $position,
            ! $unavailable,
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
