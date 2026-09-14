<?php

namespace App\Integrations\ExportMatching\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Contracts\CatalogSearch;
use App\Integrations\ExportMatching\Data\CatalogCandidate;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\MatchingFailure;
use App\Integrations\ExportMatching\YouTubeMatchClassifier;
use DateInterval;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class YouTubeCatalogSearch implements CatalogSearch
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    public function __construct(private YouTubeMatchClassifier $classifier) {}

    public function search(SourceTrack $source, StreamingProvider $provider, ?string $market): MatchResult|MatchingFailure
    {
        if ($provider !== StreamingProvider::YouTube) {
            return MatchingFailure::InvalidResponse;
        }

        if (! $source->available || $source->title === null) {
            return MatchResult::unavailable();
        }

        $key = config('services.export_matching.youtube.api_key');
        if (! is_string($key) || $key === '' || str_starts_with($key, '__REQUIRED_')) {
            return MatchingFailure::TemporarilyUnavailable;
        }

        $query = trim($source->title.' '.implode(' ', $source->creators));

        try {
            $search = Http::acceptJson()->connectTimeout(5)->timeout(10)->get(self::API_URL.'/search', [
                'part' => 'snippet',
                'type' => 'video',
                'maxResults' => 5,
                'q' => mb_substr($query, 0, 300),
                'key' => $key,
            ]);
        } catch (Throwable) {
            return MatchingFailure::TemporarilyUnavailable;
        }

        if (! $search->successful()) {
            return $this->failure($search);
        }

        $items = $search->json('items');
        if (! is_array($items) || ! array_is_list($items) || count($items) > 5) {
            return MatchingFailure::InvalidResponse;
        }

        $ids = [];
        foreach ($items as $item) {
            $id = is_array($item) ? ($item['id']['videoId'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                return MatchingFailure::InvalidResponse;
            }
            $ids[] = $id;
        }

        if ($ids === []) {
            return MatchResult::unavailable();
        }

        try {
            $metadata = Http::acceptJson()->connectTimeout(5)->timeout(10)->get(self::API_URL.'/videos', [
                'part' => 'snippet,contentDetails,status',
                'id' => implode(',', $ids),
                'key' => $key,
            ]);
        } catch (Throwable) {
            return MatchingFailure::TemporarilyUnavailable;
        }

        if (! $metadata->successful()) {
            return $this->failure($metadata);
        }

        $videos = $metadata->json('items');
        if (! is_array($videos) || ! array_is_list($videos) || count($videos) > count($ids)) {
            return MatchingFailure::InvalidResponse;
        }

        $candidates = [];
        foreach ($videos as $video) {
            if (! is_array($video)) {
                return MatchingFailure::InvalidResponse;
            }

            $privacy = $video['status']['privacyStatus'] ?? null;
            $upload = $video['status']['uploadStatus'] ?? null;
            $embeddable = $video['status']['embeddable'] ?? null;
            if (! is_string($privacy) || ! is_string($upload) || ! is_bool($embeddable)) {
                return MatchingFailure::InvalidResponse;
            }

            if ($privacy !== 'public' || $upload !== 'processed' || ! $embeddable) {
                continue;
            }

            $candidate = $this->candidate($video);
            if (! $candidate instanceof CatalogCandidate) {
                return MatchingFailure::InvalidResponse;
            }
            $candidates[] = $candidate;
        }

        return $this->classifier->classify($source, $candidates);
    }

    private function candidate(array $video): ?CatalogCandidate
    {
        $id = $video['id'] ?? null;
        $title = $video['snippet']['title'] ?? null;
        $channel = $video['snippet']['channelTitle'] ?? null;
        $duration = $video['contentDetails']['duration'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($title) || $title === ''
            || ! is_string($channel) || $channel === '' || ! is_string($duration)) {
            return null;
        }

        try {
            $interval = new DateInterval($duration);
        } catch (Throwable) {
            return null;
        }

        $milliseconds = (($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s) * 1000;

        return new CatalogCandidate($id, 'https://www.youtube.com/watch?v='.$id, $title, [$channel], null, $milliseconds);
    }

    private function failure(Response $response): MatchingFailure
    {
        $reason = $response->json('error.errors.0.reason');
        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            return MatchingFailure::QuotaLimited;
        }
        if ($response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return MatchingFailure::RateLimited;
        }

        return match ($response->status()) {
            401 => MatchingFailure::Unauthorized,
            403 => MatchingFailure::Forbidden,
            default => $response->serverError() ? MatchingFailure::TemporarilyUnavailable : MatchingFailure::InvalidResponse,
        };
    }
}
