<?php

namespace App\Integrations\ManagedAccountExport\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookup;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistItem;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReconciliationResult;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedExportMarker;
use App\Integrations\ManagedAccountExport\ManagedPlaylistMetadataFactory;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Integrations\ManagedAccountExport\ManagedProviderFailureMapper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SpotifyManagedPlaylistGateway implements ManagedPlaylistGateway
{
    public const REQUIRED_SCOPES = [
        'playlist-modify-private',
        'playlist-read-collaborative',
        'playlist-read-private',
        'user-read-private',
    ];

    private const API_URL = 'https://api.spotify.com/v1';

    private const MAX_PAGES = 20;

    public function provider(): StreamingProvider
    {
        return StreamingProvider::Spotify;
    }

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        if (! $this->validAccess($access) || ! ManagedExportMarker::isValid($marker)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }

        $matches = [];
        $url = self::API_URL.'/me/playlists?limit=50';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $response = $this->request($access)->get($url);
            } catch (Throwable) {
                return ManagedMarkerLookup::inconclusive();
            }
            if (! $response->successful()) {
                $failure = ManagedProviderFailureMapper::response($response);

                return $failure->code === ManagedExportFailureCode::TransportUnavailable
                    ? ManagedMarkerLookup::inconclusive()
                    : $failure;
            }
            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['items'] ?? null) || ! array_is_list($payload['items'])) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            foreach ($payload['items'] as $item) {
                if (! is_array($item)) {
                    return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
                }
                $description = $item['description'] ?? null;
                if (is_string($description) && ManagedExportMarker::appearsExactlyOnce($description, $marker)) {
                    $reference = $this->reference($item);
                    if ($reference instanceof ManagedProviderFailure) {
                        return $reference;
                    }
                    if ($reference->ownerAccountId !== $access->providerAccountId) {
                        return new ManagedProviderFailure(ManagedExportFailureCode::TargetOwnerMismatch);
                    }
                    if (($item['public'] ?? null) !== false || ($item['collaborative'] ?? null) !== false) {
                        return new ManagedProviderFailure(ManagedExportFailureCode::TargetVisibilityMismatch);
                    }
                    $matches[] = $reference;
                }
            }
            $next = $payload['next'] ?? null;
            if ($next === null) {
                return match (count($matches)) {
                    0 => ManagedMarkerLookup::none(),
                    1 => ManagedMarkerLookup::one($matches[0]),
                    default => ManagedMarkerLookup::ambiguous($matches),
                };
            }
            if (! is_string($next) || ! str_starts_with($next, self::API_URL.'/')) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            $url = $next;
        }

        return ManagedMarkerLookup::inconclusive();
    }

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure
    {
        if (! $this->validAccess($access) || ! $this->validMetadata($metadata)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::MetadataRejected);
        }
        try {
            if (($failure = $access->mutationFailure()) !== null) {
                return new ManagedProviderFailure($failure);
            }
            $response = $this->request($access)->post(
                self::API_URL.'/me/playlists',
                ['name' => $metadata->title, 'description' => $metadata->description, 'public' => false, 'collaborative' => false],
            );
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }
        if (! $response->successful()) {
            return $response->serverError()
                ? ManagedProviderFailureMapper::transport(true)
                : ManagedProviderFailureMapper::response($response, ManagedExportFailureCode::MetadataRejected);
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation);
        }
        $reference = $this->reference($payload);
        if ($reference instanceof ManagedProviderFailure
            || $reference->ownerAccountId !== $access->providerAccountId
            || ($payload['public'] ?? null) !== false
            || ($payload['collaborative'] ?? null) !== false) {
            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation);
        }

        return $reference;
    }

    public function inspect(ManagedAccessContext $access, ManagedPlaylistReference $reference): ManagedPlaylistSnapshot|ManagedProviderFailure
    {
        if (! $this->validAccess($access) || $reference->provider !== $this->provider()) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        try {
            $metadataResponse = $this->request($access)->get(self::API_URL.'/playlists/'.rawurlencode($reference->providerPlaylistId));
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport();
        }
        if (! $metadataResponse->successful()) {
            return ManagedProviderFailureMapper::response($metadataResponse);
        }
        $metadata = $metadataResponse->json();
        if (! is_array($metadata)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        $actual = $this->reference($metadata);
        if ($actual instanceof ManagedProviderFailure || $actual->providerPlaylistId !== $reference->providerPlaylistId) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        if ($actual->ownerAccountId !== $access->providerAccountId || $reference->ownerAccountId !== $access->providerAccountId) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetOwnerMismatch);
        }
        if (($metadata['public'] ?? null) !== false || ($metadata['collaborative'] ?? null) !== false) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetVisibilityMismatch);
        }
        $description = $metadata['description'] ?? null;
        $title = $metadata['name'] ?? null;
        $marker = $this->markerFrom($description);
        if (! is_string($title) || $title === '' || ! is_string($description) || ($access->requireTargetMarker && $marker === null)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
        }
        $items = $this->items($access, $reference);
        if ($items instanceof ManagedProviderFailure) {
            return $items;
        }

        return new ManagedPlaylistSnapshot(
            $actual,
            new ManagedPlaylistMetadata($title, $description, $marker ?? '', $access->requireTargetMarker),
            'private',
            $items,
            is_string($metadata['snapshot_id'] ?? null) ? $metadata['snapshot_id'] : null,
        );
    }

    public function reconcile(ManagedAccessContext $access, ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata, array $catalogItems): ManagedPlaylistReconciliationResult|ManagedProviderFailure
    {
        if (count($catalogItems) > 20 || ! $this->validMetadata($metadata)
            || array_filter($catalogItems, fn (mixed $item): bool => ! is_string($item) || ! str_starts_with($item, 'spotify:track:')) !== []) {
            return new ManagedProviderFailure(ManagedExportFailureCode::ItemRejected);
        }
        $snapshot = $this->inspect($access, $reference);
        if ($snapshot instanceof ManagedProviderFailure) {
            return $snapshot;
        }
        if ($access->requireTargetMarker && $snapshot->metadata->marker !== $metadata->marker) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
        }
        try {
            if (($failure = $access->mutationFailure()) !== null) {
                return new ManagedProviderFailure($failure);
            }
            $metadataResponse = $this->request($access)->put(self::API_URL.'/playlists/'.rawurlencode($reference->providerPlaylistId), [
                'name' => $metadata->title,
                'description' => $metadata->description,
                'public' => false,
                'collaborative' => false,
            ]);
            if (! $metadataResponse->successful()) {
                return $metadataResponse->serverError()
                    ? ManagedProviderFailureMapper::transport(true)
                    : ManagedProviderFailureMapper::response($metadataResponse, ManagedExportFailureCode::MetadataRejected);
            }
            if (($failure = $access->mutationFailure()) !== null) {
                return new ManagedProviderFailure($failure);
            }
            $itemsResponse = $this->request($access)->put(
                self::API_URL.'/playlists/'.rawurlencode($reference->providerPlaylistId).'/items',
                ['uris' => array_values($catalogItems)],
            );
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }
        if (! $itemsResponse->successful()) {
            return $itemsResponse->serverError()
                ? ManagedProviderFailureMapper::transport(true)
                : ManagedProviderFailureMapper::response($itemsResponse, ManagedExportFailureCode::ItemRejected);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $verified = $this->inspect($access, $reference);
            if ($verified instanceof ManagedProviderFailure) {
                return $verified;
            }
            if ($verified->metadata->title === $metadata->title
                && $verified->metadata->description === $metadata->description
                && array_map(fn (ManagedPlaylistItem $item): string => $item->catalogUri, $verified->items) === array_values($catalogItems)) {
                return ManagedPlaylistReconciliationResult::exact($verified);
            }
        }

        return ManagedPlaylistReconciliationResult::notExact($verified);
    }

    /** @return list<ManagedPlaylistItem>|ManagedProviderFailure */
    private function items(ManagedAccessContext $access, ManagedPlaylistReference $reference): array|ManagedProviderFailure
    {
        try {
            $response = $this->request($access)->get(self::API_URL.'/playlists/'.rawurlencode($reference->providerPlaylistId).'/items', ['limit' => 21, 'offset' => 0]);
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport();
        }
        if (! $response->successful()) {
            return ManagedProviderFailureMapper::response($response);
        }
        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['items'] ?? null) || ! array_is_list($payload['items'])
            || count($payload['items']) > 20 || ($payload['next'] ?? null) !== null) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        $items = [];
        foreach ($payload['items'] as $position => $item) {
            $track = is_array($item) ? ($item['item'] ?? $item['track'] ?? null) : null;
            $id = is_array($track) ? ($track['id'] ?? null) : null;
            $uri = is_array($track) ? ($track['uri'] ?? null) : null;
            if (! is_string($id) || $id === '' || ! is_string($uri) || $uri === '') {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            $items[] = new ManagedPlaylistItem($id, $uri, $position);
        }

        return $items;
    }

    private function request(ManagedAccessContext $access): PendingRequest
    {
        return Http::withToken($access->accessToken)->acceptJson()->connectTimeout(5)->timeout(15);
    }

    private function validAccess(ManagedAccessContext $access): bool
    {
        return $access->provider === $this->provider() && $access->providerAccountId !== '';
    }

    private function validMetadata(ManagedPlaylistMetadata $metadata): bool
    {
        return mb_strlen($metadata->title) <= ManagedPlaylistMetadataFactory::SPOTIFY_TITLE_LIMIT
            && mb_strlen($metadata->description) <= ManagedPlaylistMetadataFactory::SPOTIFY_DESCRIPTION_LIMIT;
    }

    private function reference(array $payload): ManagedPlaylistReference|ManagedProviderFailure
    {
        $id = $payload['id'] ?? null;
        $url = $payload['external_urls']['spotify'] ?? null;
        $owner = $payload['owner']['id'] ?? null;
        if (! is_string($id) || preg_match('/^[^\x00-\x20]{1,255}$/D', $id) !== 1
            || ! is_string($url) || $url !== 'https://open.spotify.com/playlist/'.rawurlencode($id)
            || ! is_string($owner) || preg_match('/^[^\x00-\x20]{1,255}$/D', $owner) !== 1) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        try {
            return new ManagedPlaylistReference($this->provider(), $id, $url, $owner);
        } catch (Throwable) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
    }

    private function markerFrom(mixed $description): ?string
    {
        if (! is_string($description)
            || preg_match('/(?:^|\R)'.preg_quote(ManagedExportMarker::PREFIX, '/').'([0-9a-f-]{36})$/u', $description, $match) !== 1
            || ! ManagedExportMarker::appearsExactlyOnce($description, $match[1])) {
            return null;
        }

        return $match[1];
    }
}
