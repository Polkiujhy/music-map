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

final class SpotifyPlaylistWriter implements PlaylistWriter
{
    private const API_URL = 'https://api.spotify.com/v1';

    private const MAX_PAGES = 20;

    private const PAGE_SIZE = 50;

    private const IO_BUDGET_SECONDS = 360.0;

    private ?float $startedAt = null;

    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
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

            $metadata = $this->request($accessToken)->get(self::API_URL.'/playlists/'.$playlist->targetId);
            if (! $metadata->successful()) {
                return PlaylistWriteResult::failed($this->failure($metadata, true));
            }

            if ($this->budgetExceeded()) {
                return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
            }

            $items = $this->request($accessToken)->get(self::API_URL.'/playlists/'.$playlist->targetId.'/items', [
                'limit' => 21,
                'offset' => 0,
            ]);
            if (! $items->successful()) {
                return PlaylistWriteResult::failed($this->failure($items, true));
            }
        } catch (Throwable) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
        }

        return $this->inspectionResult($playlist, $metadata, $items);
    }

    public function recover(string $accessToken, ExportPlaylistDefinition $playlist): CreateRecoveryResult
    {
        if (! $this->validDefinition($playlist, false)) {
            return CreateRecoveryResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $matches = [];
        $seen = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                if ($this->budgetExceeded()) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }

                $response = $this->request($accessToken)->get(self::API_URL.'/me/playlists', [
                    'limit' => self::PAGE_SIZE,
                    'offset' => $page * self::PAGE_SIZE,
                ]);
            } catch (Throwable) {
                return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
            }

            if (! $response->successful()) {
                return CreateRecoveryResult::failed($this->recoveryFailure($response));
            }

            $payload = $response->json();
            $items = is_array($payload) ? ($payload['items'] ?? null) : null;
            $total = is_array($payload) ? ($payload['total'] ?? null) : null;
            $next = is_array($payload) && array_key_exists('next', $payload) ? $payload['next'] : false;

            if (! is_array($items) || ! array_is_list($items) || count($items) > self::PAGE_SIZE
                || ! is_int($total) || $total < 0 || $total > 1000
                || ($next !== null && ! is_string($next))) {
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
                $owner = $item['owner']['id'] ?? null;
                $description = $item['description'] ?? null;
                $public = $item['public'] ?? null;
                if (! is_string($id) || ! ProviderPlaylistUrl::validId(StreamingProvider::Spotify, $id)
                    || ! is_string($owner) || ! is_string($description) || ! is_bool($public)) {
                    return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
                }

                if ($owner === $playlist->targetAccountId && $description === $playlist->markedDescription() && ! $public) {
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
        }

        return CreateRecoveryResult::failed(PlaylistWriteFailure::RecoveryScanIncomplete);
    }

    public function create(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
    ): PlaylistWriteResult {
        if (! $this->validDefinition($playlist, false) || $playlist->visibility !== 'private') {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        if ($this->budgetExceeded()) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::TemporaryFailure);
        }

        if (($failure = $guard->failure()) !== null) {
            return PlaylistWriteResult::failed($failure);
        }

        try {
            $response = $this->request($accessToken)->post(self::API_URL.'/me/playlists', [
                'name' => $playlist->name,
                'description' => $playlist->markedDescription(),
                'public' => false,
                'collaborative' => false,
            ]);
        } catch (Throwable) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::AmbiguousCreate);
        }

        if (! $response->successful()) {
            return PlaylistWriteResult::failed($this->failure($response));
        }

        $id = $response->json('id');
        if (! is_string($id) || ! ProviderPlaylistUrl::validId(StreamingProvider::Spotify, $id)) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $revision = $response->json('snapshot_id');
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
        if (! $this->validDefinition($playlist, true) || $playlist->visibility !== 'private') {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $metadata = $this->mutate($guard, fn (): Response => $this->request($accessToken)
            ->put(self::API_URL.'/playlists/'.$playlist->targetId, [
                'name' => $playlist->name,
                'description' => $playlist->markedDescription(),
                'public' => false,
                'collaborative' => false,
            ]));
        if ($metadata instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($metadata);
        }

        $items = $this->mutate($guard, fn (): Response => $this->request($accessToken)
            ->put(self::API_URL.'/playlists/'.$playlist->targetId.'/items', ['uris' => $playlist->items]));
        if ($items instanceof PlaylistWriteFailure) {
            return PlaylistWriteResult::failed($items);
        }

        $revision = $items->json('snapshot_id');
        if (! is_string($revision) || $revision === '' || strlen($revision) > 255) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        return PlaylistWriteResult::success($playlist->targetId, $revision, $playlist->items);
    }

    private function inspectionResult(
        ExportPlaylistDefinition $playlist,
        Response $metadata,
        Response $itemsResponse,
    ): PlaylistWriteResult {
        $id = $metadata->json('id');
        $owner = $metadata->json('owner.id');
        $public = $metadata->json('public');
        $collaborative = $metadata->json('collaborative');
        $revision = $metadata->json('snapshot_id');
        $payload = $itemsResponse->json();
        $items = is_array($payload) ? ($payload['items'] ?? null) : null;
        $total = is_array($payload) ? ($payload['total'] ?? null) : null;

        if ($id !== $playlist->targetId || $owner !== $playlist->targetAccountId
            || $public !== false || $collaborative !== false
            || ($revision !== null && (! is_string($revision) || $revision === '' || strlen($revision) > 255))
            || ! is_array($items) || ! array_is_list($items) || count($items) > 20
            || ! is_int($total) || $total !== count($items) || ($payload['next'] ?? null) !== null) {
            return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
        }

        $uris = [];
        foreach ($items as $item) {
            $uri = is_array($item) ? ($item['item']['uri'] ?? null) : null;
            if (! $this->validTrackUri($uri)) {
                return PlaylistWriteResult::failed(PlaylistWriteFailure::InvalidResponse);
            }
            $uris[] = $uri;
        }

        return PlaylistWriteResult::success($id, $revision, $uris);
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
        if ($playlist->provider !== StreamingProvider::Spotify
            || $playlist->targetAccountId === '' || strlen($playlist->targetAccountId) > 255
            || $playlist->name === '' || strlen($playlist->name) > 100
            || strlen($playlist->markedDescription()) > 300
            || $playlist->marker === '' || strlen($playlist->marker) > 100
            || ! array_is_list($playlist->items) || count($playlist->items) > 20
            || ($requiresTarget && ($playlist->targetId === null
                || ! ProviderPlaylistUrl::validId(StreamingProvider::Spotify, $playlist->targetId)))) {
            return false;
        }

        foreach ($playlist->items as $uri) {
            if (! $this->validTrackUri($uri)) {
                return false;
            }
        }

        return true;
    }

    private function validTrackUri(mixed $uri): bool
    {
        return is_string($uri) && preg_match('/\Aspotify:track:[A-Za-z0-9]{22}\z/D', $uri) === 1;
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
        return match ($response->status()) {
            401 => PlaylistWriteFailure::ReconnectRequired,
            403 => PlaylistWriteFailure::AccessDenied,
            404 => $targetRequest ? PlaylistWriteFailure::TargetDeleted : PlaylistWriteFailure::InvalidResponse,
            429 => PlaylistWriteFailure::RateLimited,
            default => $response->serverError()
                ? PlaylistWriteFailure::TemporaryFailure
                : PlaylistWriteFailure::InvalidResponse,
        };
    }
}
