<?php

namespace Tests\Unit\Integrations\ExportMatching;

use App\Enums\ExportMatchStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\MatchingFailure;
use App\Integrations\ExportMatching\Providers\SpotifyCatalogSearch;
use App\Integrations\ExportMatching\Providers\YouTubeCatalogSearch;
use App\Integrations\ExportMatching\SpotifyClientCredentials;
use App\Integrations\ExportMatching\SpotifyMatchClassifier;
use App\Integrations\ExportMatching\YouTubeMatchClassifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.export_matching.spotify.client_id' => 'client-canary',
            'services.export_matching.spotify.client_secret' => 'secret-canary',
            'services.export_matching.youtube.api_key' => 'youtube-key-canary',
        ]);
    }

    public function test_spotify_uses_client_credentials_then_isrc_with_market_and_bounds(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ephemeral-token'])
            ->push($this->spotifyPayload());

        $result = $this->spotify()->search($this->source(isrc: 'GBABC1234567'), StreamingProvider::Spotify, 'GB');

        $this->assertInstanceOf(MatchResult::class, $result);
        $this->assertSame(ExportMatchStatus::Matched, $result->status);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://accounts.spotify.com/api/token'
            && $request['grant_type'] === 'client_credentials');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.spotify.com/v1/search?')
            && $request['q'] === 'isrc:GBABC1234567'
            && $request['type'] === 'track'
            && $request['market'] === 'GB'
            && $request['limit'] === 5
            && $request->hasHeader('Authorization', 'Bearer ephemeral-token'));
    }

    public function test_unsearchable_source_short_circuits_without_catalog_requests(): void
    {
        $unavailable = new SourceTrack(0, null, 'Canary Song', [], null, null, null, false);
        $missingTitle = new SourceTrack(0, null, null, [], null, null, null, true);

        $this->assertSame(
            ExportMatchStatus::Unavailable,
            $this->spotify()->search($unavailable, StreamingProvider::Spotify, 'GB')->status,
        );
        $this->assertSame(
            ExportMatchStatus::Unavailable,
            $this->youtube()->search($unavailable, StreamingProvider::YouTube, null)->status,
        );
        $this->assertSame(
            ExportMatchStatus::Unavailable,
            $this->spotify()->search($missingTitle, StreamingProvider::Spotify, 'GB')->status,
        );
        $this->assertSame(
            ExportMatchStatus::Unavailable,
            $this->youtube()->search($missingTitle, StreamingProvider::YouTube, null)->status,
        );
        Http::assertNothingSent();
    }

    public function test_spotify_falls_back_to_bounded_title_and_all_artists_after_empty_isrc_search(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'ephemeral-token'])
            ->push(['tracks' => ['items' => []]])
            ->push($this->spotifyPayload());

        $result = $this->spotify()->search(
            $this->source(creators: ['One', 'Two'], isrc: 'GBABC1234567'),
            StreamingProvider::Spotify,
            'GB',
        );

        $this->assertInstanceOf(MatchResult::class, $result);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.spotify.com/v1/search?')
            && str_contains((string) $request['q'], 'track:"Canary Song"')
            && str_contains((string) $request['q'], 'artist:"One"')
            && str_contains((string) $request['q'], 'artist:"Two"')
            && $request['limit'] === 5);
    }

    #[DataProvider('spotifyFailures')]
    public function test_spotify_maps_closed_failures_without_leaking_credentials(int $status, MatchingFailure $expected): void
    {
        Http::fakeSequence()->push(['access_token' => 'ephemeral-token'])->push([], $status);

        $result = $this->spotify()->search($this->source(), StreamingProvider::Spotify, 'GB');

        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString('ephemeral-token', $result->value);
        $this->assertStringNotContainsString('secret-canary', $result->value);
    }

    public static function spotifyFailures(): array
    {
        return [
            [401, MatchingFailure::Unauthorized],
            [403, MatchingFailure::Forbidden],
            [429, MatchingFailure::RateLimited],
            [503, MatchingFailure::TemporarilyUnavailable],
        ];
    }

    public function test_spotify_rejects_bad_json_missing_fields_and_transport_failure(): void
    {
        $catalogResponse = Http::response('not-json');
        Http::fake(function (Request $request) use (&$catalogResponse): mixed {
            return $request->url() === 'https://accounts.spotify.com/api/token'
                ? Http::response(['access_token' => 'ephemeral-token'])
                : $catalogResponse;
        });
        $this->assertSame(MatchingFailure::InvalidResponse, $this->spotify()->search($this->source(), StreamingProvider::Spotify, 'GB'));

        $catalogResponse = Http::response(['tracks' => ['items' => [['id' => 'missing-fields']]]]);
        $this->assertSame(MatchingFailure::InvalidResponse, $this->spotify()->search($this->source(), StreamingProvider::Spotify, 'GB'));

        $catalogResponse = Http::failedConnection('private transport detail');
        $this->assertSame(MatchingFailure::TemporarilyUnavailable, $this->spotify()->search($this->source(), StreamingProvider::Spotify, 'GB'));
    }

    public function test_youtube_uses_video_only_bounded_search_then_batch_metadata(): void
    {
        Http::fakeSequence()->push($this->youtubeSearch())->push($this->youtubeMetadata());

        $result = $this->youtube()->search($this->source(), StreamingProvider::YouTube, null);

        $this->assertInstanceOf(MatchResult::class, $result);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/search?')
            && $request['type'] === 'video' && $request['maxResults'] === 5
            && $request['key'] === 'youtube-key-canary');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/videos?')
            && $request['id'] === 'video-canary'
            && $request['part'] === 'snippet,contentDetails,status');
    }

    #[DataProvider('youtubeFailures')]
    public function test_youtube_maps_status_quota_and_rate_failures(int $status, array $body, MatchingFailure $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $result = $this->youtube()->search($this->source(), StreamingProvider::YouTube, null);

        $this->assertSame($expected, $result);
        $this->assertStringNotContainsString('youtube-key-canary', $result->value);
    }

    public static function youtubeFailures(): array
    {
        return [
            [401, [], MatchingFailure::Unauthorized],
            [403, [], MatchingFailure::Forbidden],
            [429, [], MatchingFailure::RateLimited],
            [403, ['error' => ['errors' => [['reason' => 'quotaExceeded']]]], MatchingFailure::QuotaLimited],
            [503, [], MatchingFailure::TemporarilyUnavailable],
        ];
    }

    public function test_youtube_rejects_bad_json_missing_metadata_fields_and_transport_failure(): void
    {
        Http::fake(['*' => Http::response('bad-json')]);
        $this->assertSame(MatchingFailure::InvalidResponse, $this->youtube()->search($this->source(), StreamingProvider::YouTube, null));

        Http::fakeSequence()->push($this->youtubeSearch())->push(['items' => [['id' => 'video-canary']]]);
        $this->assertSame(MatchingFailure::InvalidResponse, $this->youtube()->search($this->source(), StreamingProvider::YouTube, null));

        Http::fakeSequence()->pushFailedConnection('private transport detail');
        $this->assertSame(MatchingFailure::TemporarilyUnavailable, $this->youtube()->search($this->source(), StreamingProvider::YouTube, null));
    }

    private function spotify(): SpotifyCatalogSearch
    {
        return new SpotifyCatalogSearch(new SpotifyClientCredentials, new SpotifyMatchClassifier);
    }

    private function youtube(): YouTubeCatalogSearch
    {
        return new YouTubeCatalogSearch(new YouTubeMatchClassifier);
    }

    private function source(array $creators = ['Canary Artist'], ?string $isrc = null): SourceTrack
    {
        return new SourceTrack(0, 'source-id', 'Canary Song', $creators, 'Canary Album', 180000, $isrc, true);
    }

    private function spotifyPayload(): array
    {
        return ['tracks' => ['items' => [[
            'id' => 'spotify-canary', 'uri' => 'spotify:track:spotify-canary', 'name' => 'Canary Song',
            'artists' => [['name' => 'Canary Artist']], 'album' => ['name' => 'Canary Album'],
            'duration_ms' => 180000, 'external_ids' => ['isrc' => 'GBABC1234567'],
        ]]]];
    }

    private function youtubeSearch(): array
    {
        return ['items' => [['id' => ['videoId' => 'video-canary']]]];
    }

    private function youtubeMetadata(): array
    {
        return ['items' => [[
            'id' => 'video-canary',
            'snippet' => ['title' => 'Canary Song', 'channelTitle' => 'Canary Artist'],
            'contentDetails' => ['duration' => 'PT3M'],
            'status' => ['privacyStatus' => 'public', 'uploadStatus' => 'processed', 'embeddable' => true],
        ]]];
    }
}
