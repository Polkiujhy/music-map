<?php

namespace App\Integrations\PlaylistExport\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\Contracts\PlaylistWriter;
use App\Integrations\PlaylistExport\Data\CreateRecoveryResult;
use App\Integrations\PlaylistExport\Data\ExportPlaylistDefinition;
use App\Integrations\PlaylistExport\Data\PlaylistWriteResult;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\PlaylistExport\ProviderPlaylistUrl;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class YouTubePlaylistWriter implements PlaylistWriter
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    private const MAX_PAGES = 20;

    private const PAGE_SIZE = 50;

    private const IO_BUDGET_SECONDS = 360.0;

    private ?float $startedAt = null;

    private Closure $clock;

    public function __construct(
        private readonly bool $supportsDuplicates = true,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    public function inspect(string $accessToken, ExportPlaylistDefinition $playlist): PlaylistWriteResult
    {
        if (! $this->validDefinition($playlist, true)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        try {
            if ($this->budgetExceeded()) {
                return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
            }

            $metadata = $this->request($accessToken)->get(self::API_URL.'/playlists', [
                'part' => 'id,snippet,status',
                'id' => $playlist->targetId,
            ]);
            if (! $metadata->successful()) {
                return PlaylistWriteResult::failed($this->failure($metadata, true));
            }
        } catch (Throwable) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
        }

        $payload = $metadata->json();
        $items = is_array($payload) ? ($payload['items'] ?? null) : null;
        if (! is_array($items) || ! array_is_list($items)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }
        if ($items === []) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::TargetDeleted);
        }
        if (count($items) !== 1 || ! is_array($items[0])) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $item = $items[0];
        $revision = $item['etag'] ?? null;
        if (($item['id'] ?? null) !== $playlist->targetId
            || ($item['snippet']['channelId'] ?? null) !== $playlist->targetAccountId
            || ($item['snippet']['description'] ?? null) !== $playlist->markedDescription()
            || ($item['status']['privacyStatus'] ?? null) !== 'unlisted'
            || ($revision !== null && (! is_string($revision) || $revision === '' || strlen($revision) > 255))) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $current = $this->currentItems($accessToken, $playlist->targetId);
        if ($current instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($current);
        }

        return PlaylistWriteResult::success(
            $playlist->targetId,
            $revision,
            array_column($current, 'videoId'),
        );
    }

    public function recover(string $accessToken, ExportPlaylistDefinition $playlist): CreateRecoveryResult
    {
        if (! $this->validDefinition($playlist, false)) {
            return CreateRecoveryResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $matches = [];
        $seen = 0;
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = [
                'part' => 'id,snippet,status',
                'mine' => 'true',
                'maxResults' => self::PAGE_SIZE,
            ];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            try {
                if ($this->budgetExceeded()) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }
                $response = $this->request($accessToken)->get(self::API_URL.'/playlists', $query);
            } catch (Throwable) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }

            if (! $response->successful()) {
                return CreateRecoveryResult::failed($this->recoveryFailure($response));
            }

            $payload = $response->json();
            $items = is_array($payload) ? ($payload['items'] ?? null) : null;
            $total = is_array($payload) ? ($payload['pageInfo']['totalResults'] ?? null) : null;
            $next = is_array($payload) ? ($payload['nextPageToken'] ?? null) : null;

            if (! is_array($items) || ! array_is_list($items) || count($items) > self::PAGE_SIZE
                || ! is_int($total) || $total < 0 || $total > 1000
                || ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 255))) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }

            $seen += count($items);
            if ($seen > $total || $seen > 1000) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }

                $id = $item['id'] ?? null;
                $owner = $item['snippet']['channelId'] ?? null;
                $description = $item['snippet']['description'] ?? null;
                $privacy = $item['status']['privacyStatus'] ?? null;
                if (! is_string($id) || ! ProviderPlaylistUrl::validId(StreamingProvider::YouTube, $id)
                    || ! is_string($owner) || ! is_string($description) || ! is_string($privacy)) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }

                if ($owner === $playlist->targetAccountId && $description === $playlist->markedDescription()
                    && $privacy === 'unlisted') {
                    $matches[] = $id;
                }
            }

            if ($this->budgetExceeded()) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }

            if ($next === null) {
                if ($seen !== $total) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }

                return count($matches) === 1
                    ? CreateRecoveryResult::recovered($matches[0])
                    : CreateRecoveryResult::failed(PlaylistWriteFailure::AmbiguousCreate);
            }

            if ($items === [] || $seen >= $total) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }
            $pageToken = $next;
        }

        return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
    }

    public function create(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
    ): PlaylistWriteResult {
        if (! $this->validDefinition($playlist, false) || $playlist->visibility !== 'unlisted') {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }
        if (! $this->supportsDuplicates && count($playlist->items) !== count(array_unique($playlist->items))) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::UnsupportedDuplicate);
        }
        if ($this->budgetExceeded()) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
        }
        if (($failure = $guard->failure()) !== null) {
            return PlaylistWriteResult::failed($failure);
        }

        try {
            $response = $this->request($accessToken)->post(self::API_URL.'/playlists?part=snippet%2Cstatus', [
                'snippet' => [
                    'title' => $playlist->name,
                    'description' => $playlist->markedDescription(),
                ],
                'status' => ['privacyStatus' => 'unlisted'],
            ]);
        } catch (Throwable) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::AmbiguousCreate);
        }

        if (! $response->successful()) {
            return PlaylistWriteResult::failed($this->failure($response));
        }

        $id = $response->json('id');
        if (! is_string($id) || ! ProviderPlaylistUrl::validId(StreamingProvider::YouTube, $id)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $revision = $response->json('etag');
        if ($revision !== null && (! is_string($revision) || $revision === '' || strlen($revision) > 255)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        return PlaylistWriteResult::success($id, $revision, []);
    }

    public function replace(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
    ): PlaylistWriteResult {
        if (! $this->validDefinition($playlist, true) || $playlist->visibility !== 'unlisted') {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }
        if (! $this->supportsDuplicates && count($playlist->items) !== count(array_unique($playlist->items))) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::UnsupportedDuplicate);
        }

        $current = $this->currentItems($accessToken, $playlist->targetId);
        if ($current instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($current);
        }

        $metadata = $this->mutate($guard, fn (): Response => $this->request($accessToken)
            ->put(self::API_URL.'/playlists?part=snippet%2Cstatus', [
                'id' => $playlist->targetId,
                'snippet' => [
                    'title' => $playlist->name,
                    'description' => $playlist->markedDescription(),
                ],
                'status' => ['privacyStatus' => 'unlisted'],
            ]));
        if ($metadata instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($metadata);
        }

        $failure = $this->applyItemPlan($accessToken, $playlist, $guard, $current);
        if ($failure !== null) {
            return PlaylistWriteResult::failed($failure);
        }

        $verified = $this->currentItems($accessToken, $playlist->targetId);
        if ($verified instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($verified);
        }
        if (array_column($verified, 'videoId') !== $playlist->items) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $revision = $metadata->json('etag');
        if ($revision !== null && (! is_string($revision) || $revision === '' || strlen($revision) > 255)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        return PlaylistWriteResult::success($playlist->targetId, $revision, $playlist->items);
    }

    /**
     * @param  list<array{occurrenceId: string, videoId: string}>  $current
     */
    private function applyItemPlan(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
        array $current,
    ): ?PlaylistWriteFailure {
        $pairs = $this->lcsPairs(array_column($current, 'videoId'), $playlist->items);
        $retained = [];
        $target = array_fill(0, count($playlist->items), null);
        foreach ($pairs as [$currentIndex, $targetIndex]) {
            $occurrence = $current[$currentIndex]['occurrenceId'];
            $retained[$occurrence] = true;
            $target[$targetIndex] = $current[$currentIndex];
        }

        $available = [];
        foreach ($current as $item) {
            if (! isset($retained[$item['occurrenceId']])) {
                $available[$item['videoId']][] = $item;
            }
        }
        foreach ($target as $index => $item) {
            if ($item === null && ($available[$playlist->items[$index]] ?? []) !== []) {
                $target[$index] = array_shift($available[$playlist->items[$index]]);
            }
        }

        $used = [];
        foreach ($target as $item) {
            if (is_array($item)) {
                $used[$item['occurrenceId']] = true;
            }
        }

        for ($index = count($current) - 1; $index >= 0; $index--) {
            if (isset($used[$current[$index]['occurrenceId']])) {
                continue;
            }
            $response = $this->mutate($guard, fn (): Response => $this->request($accessToken)
                ->delete(self::API_URL.'/playlistItems?id='.rawurlencode($current[$index]['occurrenceId'])));
            if ($response instanceof PlaylistWriteFailure) {
                return $response;
            }
            array_splice($current, $index, 1);
        }

        $steps = 0;
        for ($position = 0; $position < count($target);) {
            if (++$steps > 80) {
                return PlaylistWriteFailure::InvalidResponse;
            }

            if ($target[$position] === null) {
                $response = $this->mutate($guard, fn (): Response => $this->request($accessToken)
                    ->post(self::API_URL.'/playlistItems?part=snippet', [
                        'snippet' => [
                            'playlistId' => $playlist->targetId,
                            'position' => $position,
                            'resourceId' => [
                                'kind' => 'youtube#video',
                                'videoId' => $playlist->items[$position],
                            ],
                        ],
                    ]));
                if ($response instanceof PlaylistWriteFailure) {
                    return $response;
                }
                $occurrenceId = $response->json('id');
                if (! is_string($occurrenceId) || ! $this->boundedId($occurrenceId)) {
                    return PlaylistWriteFailure::InvalidResponse;
                }
                $target[$position] = [
                    'occurrenceId' => $occurrenceId,
                    'videoId' => $playlist->items[$position],
                ];
                array_splice($current, $position, 0, [$target[$position]]);
                $position++;

                continue;
            }

            if (($current[$position]['occurrenceId'] ?? null) === $target[$position]['occurrenceId']) {
                $position++;

                continue;
            }

            $desiredOccurrence = $target[$position]['occurrenceId'];
            $desiredCurrentIndex = $this->occurrenceIndex($current, $desiredOccurrence);
            if ($desiredCurrentIndex === null) {
                return PlaylistWriteFailure::InvalidResponse;
            }

            if (! isset($retained[$desiredOccurrence])) {
                $moveIndex = $desiredCurrentIndex;
                $destination = $position;
            } else {
                $moveIndex = $position;
                $destination = $this->occurrenceIndex($target, $current[$position]['occurrenceId']);
                if ($destination === null) {
                    return PlaylistWriteFailure::InvalidResponse;
                }
            }

            $moving = $current[$moveIndex];
            $response = $this->mutate($guard, fn (): Response => $this->request($accessToken)
                ->put(self::API_URL.'/playlistItems?part=snippet', [
                    'id' => $moving['occurrenceId'],
                    'snippet' => [
                        'playlistId' => $playlist->targetId,
                        'position' => $destination,
                        'resourceId' => [
                            'kind' => 'youtube#video',
                            'videoId' => $moving['videoId'],
                        ],
                    ],
                ]));
            if ($response instanceof PlaylistWriteFailure) {
                return $response;
            }

            array_splice($current, $moveIndex, 1);
            array_splice($current, $destination, 0, [$moving]);
        }

        return null;
    }

    /** @return list<array{occurrenceId: string, videoId: string}>|PlaylistWriteFailure */
    private function currentItems(string $accessToken, string $playlistId): array|PlaylistWriteFailure
    {
        try {
            if ($this->budgetExceeded()) {
                return PlaylistWriteFailure::TemporaryFailure;
            }
            $response = $this->request($accessToken)->get(self::API_URL.'/playlistItems', [
                'part' => 'id,snippet',
                'playlistId' => $playlistId,
                'maxResults' => 50,
            ]);
        } catch (Throwable) {
            return PlaylistWriteFailure::TemporaryFailure;
        }

        if (! $response->successful()) {
            return $this->failure($response, true);
        }

        $payload = $response->json();
        $items = is_array($payload) ? ($payload['items'] ?? null) : null;
        $total = is_array($payload) ? ($payload['pageInfo']['totalResults'] ?? null) : null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > 20
            || ! is_int($total) || $total !== count($items) || array_key_exists('nextPageToken', $payload)) {
            return PlaylistWriteFailure::InvalidResponse;
        }

        $result = [];
        foreach ($items as $position => $item) {
            $occurrenceId = is_array($item) ? ($item['id'] ?? null) : null;
            $videoId = is_array($item) ? ($item['snippet']['resourceId']['videoId'] ?? null) : null;
            $actualPosition = is_array($item) ? ($item['snippet']['position'] ?? null) : null;
            if (! is_string($occurrenceId) || ! $this->boundedId($occurrenceId)
                || ! $this->validVideoId($videoId) || $actualPosition !== $position) {
                return PlaylistWriteFailure::InvalidResponse;
            }
            $result[] = ['occurrenceId' => $occurrenceId, 'videoId' => $videoId];
        }

        return $result;
    }

    /** @return list<array{int, int}> */
    private function lcsPairs(array $current, array $target): array
    {
        $lengths = array_fill(0, count($current) + 1, array_fill(0, count($target) + 1, 0));
        for ($i = count($current) - 1; $i >= 0; $i--) {
            for ($j = count($target) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $current[$i] === $target[$j]
                    ? 1 + $lengths[$i + 1][$j + 1]
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $pairs = [];
        for ($i = 0, $j = 0; $i < count($current) && $j < count($target);) {
            if ($current[$i] === $target[$j]) {
                $pairs[] = [$i++, $j++];
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }

    private function occurrenceIndex(array $items, string $occurrenceId): ?int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['occurrenceId'] ?? null) === $occurrenceId) {
                return $index;
            }
        }

        return null;
    }

    private function mutate(ExportMutationGuard $guard, Closure $request): Response|PlaylistWriteFailure
    {
        if ($this->budgetExceeded()) {
            return PlaylistWriteFailure::TemporaryFailure;
        }
        if (($failure = $guard->failure()) !== null) {
            return $failure;
        }

        try {
            $response = $request();
        } catch (Throwable) {
            return PlaylistWriteFailure::TemporaryFailure;
        }

        return $response->successful() ? $response : $this->failure($response, true);
    }

    private function validDefinition(ExportPlaylistDefinition $playlist, bool $requiresTarget): bool
    {
        if ($playlist->provider !== StreamingProvider::YouTube
            || $playlist->targetAccountId === '' || strlen($playlist->targetAccountId) > 255
            || $playlist->name === '' || strlen($playlist->name) > 150
            || strlen($playlist->markedDescription()) > 5000
            || $playlist->marker === '' || strlen($playlist->marker) > 100
            || ! array_is_list($playlist->items) || count($playlist->items) > 20
            || ($requiresTarget && ($playlist->targetId === null
                || ! ProviderPlaylistUrl::validId(StreamingProvider::YouTube, $playlist->targetId)))) {
            return false;
        }

        foreach ($playlist->items as $id) {
            if (! $this->validVideoId($id)) {
                return false;
            }
        }

        return true;
    }

    private function validVideoId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{11}\z/D', $id) === 1;
    }

    private function boundedId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $id) !== 1;
    }

    private function request(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->asJson()->connectTimeout(5)->timeout(10);
    }

    private function budgetExceeded(): bool
    {
        $now = ($this->clock)();
        $this->startedAt ??= $now;

        return $now - $this->startedAt > self::IO_BUDGET_SECONDS;
    }

    private function recoveryFailure(Response $response): PlaylistWriteFailure
    {
        $failure = $this->failure($response);

        return $failure === PlaylistWriteFailure::InvalidResponse
            ? PlaylistWriteFailure::RecoveryScanIncomplete
            : $failure;
    }

    private function failure(Response $response, bool $targetRequest = false): PlaylistWriteFailure
    {
        $reason = $response->json('error.errors.0.reason');
        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            return PlaylistWriteFailure::QuotaLimited;
        }
        if ($response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return PlaylistWriteFailure::RateLimited;
        }

        return match ($response->status()) {
            401 => PlaylistWriteFailure::ReconnectRequired,
            403 => PlaylistWriteFailure::AccessDenied,
            404 => $targetRequest ? PlaylistWriteFailure::TargetDeleted : PlaylistWriteFailure::InvalidResponse,
            default => $response->serverError()
                ? PlaylistWriteFailure::TemporaryFailure
                : PlaylistWriteFailure::InvalidResponse,
        };
    }
}
