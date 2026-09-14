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

final class YouTubeManagedPlaylistGateway implements ManagedPlaylistGateway
{
    public const REQUIRED_SCOPES = ['https://www.googleapis.com/auth/youtube'];

    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    private const MAX_PAGES = 20;

    public function provider(): StreamingProvider
    {
        return StreamingProvider::YouTube;
    }

    public function findByMarker(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        if (! $this->validAccess($access) || ! ManagedExportMarker::isValid($marker)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        $first = $this->scan($access, $marker);
        if ($first instanceof ManagedProviderFailure || $first->status->value === 'inconclusive') {
            return $first;
        }
        $second = $this->scan($access, $marker);
        if ($second instanceof ManagedProviderFailure || $second->status->value === 'inconclusive') {
            return $second;
        }
        if ($this->lookupSignature($first) !== $this->lookupSignature($second)) {
            return ManagedMarkerLookup::inconclusive();
        }

        return $second;
    }

    public function create(ManagedAccessContext $access, ManagedPlaylistMetadata $metadata): ManagedPlaylistReference|ManagedProviderFailure
    {
        if (! $this->validAccess($access) || ! $this->validMetadata($metadata)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::MetadataRejected);
        }
        try {
            $response = $this->request($access)->post(self::API_URL.'/playlists?part=snippet,status', [
                'snippet' => ['title' => $metadata->title, 'description' => $metadata->description],
                'status' => ['privacyStatus' => 'unlisted'],
            ]);
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
            || ($payload['status']['privacyStatus'] ?? null) !== 'unlisted') {
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
            $response = $this->request($access)->get(self::API_URL.'/playlists', [
                'part' => 'snippet,status',
                'id' => $reference->providerPlaylistId,
                'maxResults' => 1,
            ]);
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport();
        }
        if (! $response->successful()) {
            return ManagedProviderFailureMapper::response($response);
        }
        $payload = $response->json();
        $rows = is_array($payload) ? ($payload['items'] ?? null) : null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        if ($rows === []) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMissing);
        }
        if (count($rows) !== 1 || ! is_array($rows[0])) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        $metadata = $rows[0];
        $actual = $this->reference($metadata);
        if ($actual instanceof ManagedProviderFailure || $actual->providerPlaylistId !== $reference->providerPlaylistId) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        if ($actual->ownerAccountId !== $access->providerAccountId || $reference->ownerAccountId !== $access->providerAccountId) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetOwnerMismatch);
        }
        if (($metadata['status']['privacyStatus'] ?? null) !== 'unlisted') {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetVisibilityMismatch);
        }
        $title = $metadata['snippet']['title'] ?? null;
        $description = $metadata['snippet']['description'] ?? null;
        $marker = $this->markerFrom($description);
        if (! is_string($title) || $title === '' || ! is_string($description) || $marker === null) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
        }
        $items = $this->items($access, $reference);
        if ($items instanceof ManagedProviderFailure) {
            return $items;
        }

        return new ManagedPlaylistSnapshot(
            $actual,
            new ManagedPlaylistMetadata($title, $description, $marker),
            'unlisted',
            $items,
            is_string($metadata['etag'] ?? null) ? $metadata['etag'] : null,
        );
    }

    public function reconcile(ManagedAccessContext $access, ManagedPlaylistReference $reference, ManagedPlaylistMetadata $metadata, array $catalogItems): ManagedPlaylistReconciliationResult|ManagedProviderFailure
    {
        if (count($catalogItems) > 20 || ! $this->validMetadata($metadata)
            || array_filter($catalogItems, fn (mixed $item): bool => ! is_string($item) || $item === '') !== []) {
            return new ManagedProviderFailure(ManagedExportFailureCode::ItemRejected);
        }
        $first = $this->inspect($access, $reference);
        if ($first instanceof ManagedProviderFailure) {
            return $first;
        }
        if ($first->metadata->marker !== $metadata->marker) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
        }
        $second = $this->inspect($access, $reference);
        if ($second instanceof ManagedProviderFailure) {
            return $second;
        }
        if ($this->snapshotSignature($first) !== $this->snapshotSignature($second)) {
            return ManagedPlaylistReconciliationResult::notExact($second);
        }

        try {
            $metadataResponse = $this->request($access)->put(self::API_URL.'/playlists?part=snippet,status', [
                'id' => $reference->providerPlaylistId,
                'snippet' => ['title' => $metadata->title, 'description' => $metadata->description],
                'status' => ['privacyStatus' => 'unlisted'],
            ]);
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }
        if (! $metadataResponse->successful()) {
            return $metadataResponse->serverError()
                ? ManagedProviderFailureMapper::transport(true)
                : ManagedProviderFailureMapper::response($metadataResponse, ManagedExportFailureCode::MetadataRejected);
        }

        $current = array_map(fn (ManagedPlaylistItem $item): string => $item->catalogId, $second->items);
        if ($current !== array_values($catalogItems)) {
            foreach (array_reverse($second->items) as $item) {
                if ($item->occurrenceId === null) {
                    return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
                }
                $failure = $this->deleteItem($access, $item->occurrenceId);
                if ($failure !== null) {
                    return $failure;
                }
            }
            foreach (array_values($catalogItems) as $position => $videoId) {
                $failure = $this->insertItem($access, $reference->providerPlaylistId, $videoId, $position);
                if ($failure !== null) {
                    return $failure;
                }
            }
        }

        $verified = $this->inspect($access, $reference);
        if ($verified instanceof ManagedProviderFailure) {
            return $verified;
        }
        $stable = $this->inspect($access, $reference);
        if ($stable instanceof ManagedProviderFailure) {
            return $stable;
        }
        if ($this->snapshotSignature($verified) !== $this->snapshotSignature($stable)
            || $stable->metadata->title !== $metadata->title
            || $stable->metadata->description !== $metadata->description
            || array_map(fn (ManagedPlaylistItem $item): string => $item->catalogId, $stable->items) !== array_values($catalogItems)) {
            return ManagedPlaylistReconciliationResult::notExact($stable);
        }

        return ManagedPlaylistReconciliationResult::exact($stable);
    }

    private function scan(ManagedAccessContext $access, string $marker): ManagedMarkerLookup|ManagedProviderFailure
    {
        $matches = [];
        $pageToken = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['part' => 'snippet,status', 'mine' => 'true', 'maxResults' => 50];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            try {
                $response = $this->request($access)->get(self::API_URL.'/playlists', $query);
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
                $description = $item['snippet']['description'] ?? null;
                if (is_string($description) && ManagedExportMarker::appearsExactlyOnce($description, $marker)) {
                    $reference = $this->reference($item);
                    if ($reference instanceof ManagedProviderFailure) {
                        return $reference;
                    }
                    if ($reference->ownerAccountId !== $access->providerAccountId) {
                        return new ManagedProviderFailure(ManagedExportFailureCode::TargetOwnerMismatch);
                    }
                    if (($item['status']['privacyStatus'] ?? null) !== 'unlisted') {
                        return new ManagedProviderFailure(ManagedExportFailureCode::TargetVisibilityMismatch);
                    }
                    $matches[] = $reference;
                }
            }
            $pageToken = $payload['nextPageToken'] ?? null;
            if ($pageToken === null) {
                return match (count($matches)) {
                    0 => ManagedMarkerLookup::none(),
                    1 => ManagedMarkerLookup::one($matches[0]),
                    default => ManagedMarkerLookup::ambiguous($matches),
                };
            }
            if (! is_string($pageToken) || $pageToken === '') {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
        }

        return ManagedMarkerLookup::inconclusive();
    }

    /** @return list<ManagedPlaylistItem>|ManagedProviderFailure */
    private function items(ManagedAccessContext $access, ManagedPlaylistReference $reference): array|ManagedProviderFailure
    {
        $items = [];
        $pageToken = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['part' => 'id,snippet,status', 'playlistId' => $reference->providerPlaylistId, 'maxResults' => 21];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            try {
                $response = $this->request($access)->get(self::API_URL.'/playlistItems', $query);
            } catch (Throwable) {
                return ManagedProviderFailureMapper::transport();
            }
            if (! $response->successful()) {
                return ManagedProviderFailureMapper::response($response);
            }
            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload['items'] ?? null) || ! array_is_list($payload['items'])) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            foreach ($payload['items'] as $row) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                $videoId = is_array($row) ? ($row['snippet']['resourceId']['videoId'] ?? null) : null;
                $position = is_array($row) ? ($row['snippet']['position'] ?? null) : null;
                if (! is_string($id) || $id === '' || ! is_string($videoId) || $videoId === ''
                    || ! is_int($position) || $position !== count($items) || count($items) >= 20) {
                    return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
                }
                $items[] = new ManagedPlaylistItem($videoId, 'https://www.youtube.com/watch?v='.$videoId, $position, $id);
            }
            $pageToken = $payload['nextPageToken'] ?? null;
            if ($pageToken === null) {
                return $items;
            }
            if (! is_string($pageToken) || $pageToken === '') {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
        }

        return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
    }

    private function deleteItem(ManagedAccessContext $access, string $occurrenceId): ?ManagedProviderFailure
    {
        try {
            $response = $this->request($access)
                ->withQueryParameters(['id' => $occurrenceId])
                ->delete(self::API_URL.'/playlistItems');
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }

        return $response->successful() ? null : ($response->serverError()
            ? ManagedProviderFailureMapper::transport(true)
            : ManagedProviderFailureMapper::response(
                $response,
                ManagedExportFailureCode::ItemRejected,
                ManagedExportFailureCode::ItemRejected,
            ));
    }

    private function insertItem(ManagedAccessContext $access, string $playlistId, string $videoId, int $position): ?ManagedProviderFailure
    {
        try {
            $response = $this->request($access)->post(self::API_URL.'/playlistItems?part=snippet', [
                'snippet' => [
                    'playlistId' => $playlistId,
                    'position' => $position,
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
                ],
            ]);
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }

        return $response->successful() ? null : ($response->serverError()
            ? ManagedProviderFailureMapper::transport(true)
            : ManagedProviderFailureMapper::response(
                $response,
                ManagedExportFailureCode::ItemRejected,
                ManagedExportFailureCode::ItemRejected,
            ));
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
        return mb_strlen($metadata->title) <= ManagedPlaylistMetadataFactory::YOUTUBE_TITLE_LIMIT
            && mb_strlen($metadata->description) <= ManagedPlaylistMetadataFactory::YOUTUBE_DESCRIPTION_LIMIT;
    }

    private function reference(array $payload): ManagedPlaylistReference|ManagedProviderFailure
    {
        $id = $payload['id'] ?? null;
        $owner = $payload['snippet']['channelId'] ?? null;
        if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $id) !== 1
            || ! is_string($owner) || preg_match('/^[^\x00-\x20]{1,255}$/D', $owner) !== 1) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }
        try {
            return new ManagedPlaylistReference($this->provider(), $id, 'https://www.youtube.com/playlist?list='.rawurlencode($id), $owner);
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

    /** @return list<array{string, string}> */
    private function lookupSignature(ManagedMarkerLookup $lookup): array
    {
        return array_map(fn (ManagedPlaylistReference $match): array => [$match->providerPlaylistId, $match->ownerAccountId], $lookup->matches);
    }

    /** @return array<string, mixed> */
    private function snapshotSignature(ManagedPlaylistSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->reference->providerPlaylistId,
            'owner' => $snapshot->reference->ownerAccountId,
            'title' => $snapshot->metadata->title,
            'description' => $snapshot->metadata->description,
            'visibility' => $snapshot->visibility,
            'items' => array_map(
                fn (ManagedPlaylistItem $item): array => [$item->occurrenceId, $item->catalogId, $item->position],
                $snapshot->items,
            ),
        ];
    }
}
