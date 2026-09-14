<?php

namespace App\Integrations\ExportMatching\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Contracts\CatalogSearch;
use App\Integrations\ExportMatching\Data\CatalogCandidate;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\MatchingFailure;
use App\Integrations\ExportMatching\SpotifyClientCredentials;
use App\Integrations\ExportMatching\SpotifyMatchClassifier;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class SpotifyCatalogSearch implements CatalogSearch
{
    private const API_URL = 'https://api.spotify.com/v1';

    public function __construct(
        private SpotifyClientCredentials $credentials,
        private SpotifyMatchClassifier $classifier,
    ) {}

    public function search(SourceTrack $source, StreamingProvider $provider, ?string $market): MatchResult|MatchingFailure
    {
        if ($provider !== StreamingProvider::Spotify || preg_match('/^[A-Z]{2}$/', $market ?? '') !== 1) {
            return MatchingFailure::InvalidResponse;
        }

        if (! $source->available || $source->title === null) {
            return MatchResult::unavailable();
        }

        $token = $this->credentials->token();

        if ($token instanceof MatchingFailure) {
            return $token;
        }

        if ($source->isrc !== null) {
            $result = $this->request($token, 'isrc:'.$source->isrc, $market);

            if ($result instanceof MatchingFailure) {
                return $result;
            }

            if ($result !== []) {
                return $this->classifier->classify($source, $result);
            }
        }

        $query = 'track:"'.$this->queryValue($source->title).'"';
        foreach ($source->creators as $creator) {
            $query .= ' artist:"'.$this->queryValue($creator).'"';
        }

        $result = $this->request($token, $query, $market);

        return $result instanceof MatchingFailure ? $result : $this->classifier->classify($source, $result);
    }

    /** @return list<CatalogCandidate>|MatchingFailure */
    private function request(string $token, string $query, string $market): array|MatchingFailure
    {
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(10)
                ->get(self::API_URL.'/search', [
                    'q' => $query,
                    'type' => 'track',
                    'market' => $market,
                    'limit' => 5,
                ]);
        } catch (Throwable) {
            return MatchingFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return $this->failure($response);
        }

        $items = $response->json('tracks.items');

        if (! is_array($items) || ! array_is_list($items) || count($items) > 5) {
            return MatchingFailure::InvalidResponse;
        }

        $candidates = [];
        foreach ($items as $item) {
            $candidate = $this->candidate($item);
            if (! $candidate instanceof CatalogCandidate) {
                return MatchingFailure::InvalidResponse;
            }
            $candidates[] = $candidate;
        }

        return $candidates;
    }

    private function candidate(mixed $item): ?CatalogCandidate
    {
        if (! is_array($item) || ! is_array($item['artists'] ?? null)) {
            return null;
        }

        $id = $item['id'] ?? null;
        $uri = $item['uri'] ?? null;
        $title = $item['name'] ?? null;
        $duration = $item['duration_ms'] ?? null;
        $album = $item['album']['name'] ?? null;
        $isrc = $item['external_ids']['isrc'] ?? null;
        $artists = array_map(fn (mixed $artist): mixed => is_array($artist) ? ($artist['name'] ?? null) : null, $item['artists']);

        if (! is_string($id) || $id === '' || ! is_string($uri) || $uri === ''
            || ! is_string($title) || $title === '' || ! is_int($duration) || $duration < 0
            || ! is_string($album) || in_array(null, $artists, true)
            || ($isrc !== null && ! is_string($isrc))) {
            return null;
        }

        return new CatalogCandidate($id, $uri, $title, $artists, $album, $duration, $isrc);
    }

    private function failure(Response $response): MatchingFailure
    {
        return match ($response->status()) {
            401 => MatchingFailure::Unauthorized,
            403 => MatchingFailure::Forbidden,
            429 => MatchingFailure::RateLimited,
            default => $response->serverError() ? MatchingFailure::TemporarilyUnavailable : MatchingFailure::InvalidResponse,
        };
    }

    private function queryValue(string $value): string
    {
        return str_replace(['\\', '"'], [' ', ' '], mb_substr($value, 0, 200));
    }
}
