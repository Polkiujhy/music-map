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
            if (($failure = $access->mutationFailure()) !== null) {
                return new ManagedProviderFailure($failure);
            }
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
        if ($access->requireTargetMarker && $first->metadata->marker !== $metadata->marker) {
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
            if (($failure = $access->mutationFailure()) !== null) {
                return new ManagedProviderFailure($failure);
            }
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

        $failure = $this->reconcileItems($access, $reference->providerPlaylistId, $second->items, array_values($catalogItems));
        if ($failure !== null) {
            return $failure;
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
        if (($failure = $access->mutationFailure()) !== null) {
            return new ManagedProviderFailure($failure);
        }
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

    private function insertItem(ManagedAccessContext $access, string $playlistId, string $videoId, int $position): string|ManagedProviderFailure
    {
        if (($failure = $access->mutationFailure()) !== null) {
            return new ManagedProviderFailure($failure);
        }
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

        if ($response->successful()) {
            $id = $response->json('id');

            return is_string($id) && $id !== '' && strlen($id) <= 255
                ? $id : ManagedProviderFailureMapper::transport(true);
        }

        return $response->serverError()
            ? ManagedProviderFailureMapper::transport(true)
            : ManagedProviderFailureMapper::response(
                $response,
                ManagedExportFailureCode::ItemRejected,
                ManagedExportFailureCode::ItemRejected,
            );
    }

    /** @param list<ManagedPlaylistItem> $items
     * @param  list<string>  $desired
     */
    private function reconcileItems(ManagedAccessContext $access, string $playlistId, array $items, array $desired): ?ManagedProviderFailure
    {
        $current = [];
        foreach ($items as $item) {
            if ($item->occurrenceId === null || in_array($item->occurrenceId, array_column($current, 'id'), true)) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            $current[] = ['id' => $item->occurrenceId, 'video' => $item->catalogId];
        }
        // Keep a longest common subsequence untouched, then reuse remaining
        // occurrences for moves. Identical videos still have distinct IDs.
        $pairs = $this->lcsPairs(array_column($current, 'video'), $desired);
        $retained = [];
        $target = array_fill(0, count($desired), null);
        foreach ($pairs as [$sourceIndex, $targetIndex]) {
            $retained[$current[$sourceIndex]['id']] = true;
            $target[$targetIndex] = $current[$sourceIndex];
        }
        $available = [];
        foreach ($current as $item) {
            if (! isset($retained[$item['id']])) {
                $available[$item['video']][] = $item;
            }
        }
        foreach ($target as $index => $item) {
            if ($item === null && ($available[$desired[$index]] ?? []) !== []) {
                $target[$index] = array_shift($available[$desired[$index]]);
            }
        }
        $used = array_column(array_filter($target), 'id');
        for ($index = count($current) - 1; $index >= 0; $index--) {
            if (in_array($current[$index]['id'], $used, true)) {
                continue;
            }
            if (($failure = $this->deleteItem($access, $current[$index]['id'])) !== null) {
                return $failure;
            }
            array_splice($current, $index, 1);
        }
        $steps = 0;
        for ($position = 0; $position < count($target);) {
            if (++$steps > 80) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            if ($target[$position] === null) {
                $id = $this->insertItem($access, $playlistId, $desired[$position], $position);
                if ($id instanceof ManagedProviderFailure) {
                    return $id;
                }
                if (in_array($id, array_column($current, 'id'), true)) {
                    return ManagedProviderFailureMapper::transport(true);
                }
                $target[$position] = ['id' => $id, 'video' => $desired[$position]];
                array_splice($current, $position, 0, [$target[$position]]);
                $position++;

                continue;
            }
            if (($current[$position]['id'] ?? null) === $target[$position]['id']) {
                $position++;

                continue;
            }
            $desiredId = $target[$position]['id'];
            $from = array_search($desiredId, array_column($current, 'id'), true);
            if ($from === false) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }
            $destination = $position;
            if (isset($retained[$desiredId])) {
                $from = $position;
                $destination = null;
                foreach ($target as $index => $item) {
                    if (($item['id'] ?? null) === $current[$from]['id']) {
                        $destination = $index;
                        break;
                    }
                }
                if ($destination === null) {
                    return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
                }
                // Missing occurrences have not been inserted yet; positions
                // sent to the provider must address the current playlist.
                $destination -= count(array_filter(array_slice($target, 0, $destination), fn ($item): bool => $item === null));
            }
            $moving = $current[$from];
            if (($failure = $this->moveItem($access, $playlistId, $moving, $destination)) !== null) {
                return $failure;
            }
            array_splice($current, $from, 1);
            array_splice($current, $destination, 0, [$moving]);
        }

        return null;
    }

    /** @return list<array{int, int}> */
    private function lcsPairs(array $current, array $desired): array
    {
        $lengths = array_fill(0, count($current) + 1, array_fill(0, count($desired) + 1, 0));
        for ($i = count($current) - 1; $i >= 0; $i--) {
            for ($j = count($desired) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $current[$i] === $desired[$j]
                    ? 1 + $lengths[$i + 1][$j + 1]
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }
        $pairs = [];
        for ($i = 0, $j = 0; $i < count($current) && $j < count($desired);) {
            if ($current[$i] === $desired[$j]) {
                $pairs[] = [$i++, $j++];
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }

    private function moveItem(ManagedAccessContext $access, string $playlistId, array $item, int $position): ?ManagedProviderFailure
    {
        if (($failure = $access->mutationFailure()) !== null) {
            return new ManagedProviderFailure($failure);
        }
        try {
            $response = $this->request($access)->put(self::API_URL.'/playlistItems?part=snippet', [
                'id' => $item['id'],
                'snippet' => ['playlistId' => $playlistId, 'position' => $position,
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $item['video']]],
            ]);
        } catch (Throwable) {
            return ManagedProviderFailureMapper::transport(true);
        }

        return $response->successful() ? null : ($response->serverError()
            ? ManagedProviderFailureMapper::transport(true)
            : ManagedProviderFailureMapper::response($response, ManagedExportFailureCode::ItemRejected, ManagedExportFailureCode::ItemRejected));
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
