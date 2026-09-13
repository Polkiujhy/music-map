<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeResult;
use App\Integrations\PlatformAccess\RefreshTokenRotationSink;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use App\Integrations\PlatformAccess\YouTubeProbe;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class YouTubeProbeTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_technical_refreshes_and_reads_one_channel_without_mutation(): void
    {
        Log::spy();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response($this->refreshPayload()),
            'www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [['id' => 'stable-account']],
            ]),
        ]);

        $result = $this->probe()->probe($this->technical());

        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame(PlatformAccessProtocol::CAPABILITIES['technical'], $result->capabilities);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request->method() === 'POST'
            && $request['client_id'] === 'client-canary'
            && $request['client_secret'] === 'secret-canary'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-canary');
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['DELETE'], true)
            || str_contains($request->url(), '/playlistItems'));
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_tester_inserts_exact_three_items_then_deletes_and_verifies_them(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['items' => [['id' => 'stable-account']]])
            ->push(['items' => [[
                'snippet' => ['channelId' => 'stable-account'],
                'status' => ['privacyStatus' => 'private'],
            ]]])
            ->push(['items' => []])
            ->push(['id' => 'playlist-item-1'])
            ->push(['id' => 'playlist-item-2'])
            ->push(['id' => 'playlist-item-3'])
            ->push(['items' => $this->youtubeItems()])
            ->push(['items' => $this->youtubeItems()])
            ->push([], 204)
            ->push([], 204)
            ->push([], 204)
            ->push(['items' => []]);

        $session = $this->tester_session();
        $result = $this->probe()->probe($session);

        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame(PlatformAccessProtocol::CAPABILITIES['tester'], $result->capabilities);
        Http::assertSentCount(13);

        $recorded = Http::recorded();
        $insertBodies = [];
        $deleteUrls = [];

        foreach ($recorded as [$request]) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/playlistItems?')) {
                $insertBodies[] = $request->data();
            }

            if ($request->method() === 'DELETE') {
                $deleteUrls[] = $request->url();
            }
        }

        $this->assertSame(array_map(
            fn (string $videoId): array => [
                'snippet' => [
                    'playlistId' => $session->playlistId,
                    'resourceId' => [
                        'kind' => 'youtube#video',
                        'videoId' => $videoId,
                    ],
                ],
            ],
            $session->itemUris,
        ), $insertBodies);
        $this->assertSame([
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-1',
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-2',
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-3',
        ], $deleteUrls);
    }

    public function test_foreign_fixture_stops_before_insert(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['items' => [['id' => 'stable-account']]])
            ->push(['items' => [[
                'snippet' => ['channelId' => 'different-account'],
                'status' => ['privacyStatus' => 'private'],
            ]]]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('fixture-invalid', $result->category);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/playlistItems'));
    }

    public function test_ambiguous_insert_is_cleanup_failed_even_when_bounded_reads_are_empty(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['items' => [['id' => 'stable-account']]])
            ->push(['items' => [[
                'snippet' => ['channelId' => 'stable-account'],
                'status' => ['privacyStatus' => 'private'],
            ]]])
            ->push(['items' => []])
            ->push([], 200)
            ->push(['items' => []])
            ->push(['items' => []]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
        Http::assertSentCount(7);
    }

    public function test_cleanup_failure_overrides_a_successful_write_verification(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['items' => [['id' => 'stable-account']]])
            ->push(['items' => [[
                'snippet' => ['channelId' => 'stable-account'],
                'status' => ['privacyStatus' => 'private'],
            ]]])
            ->push(['items' => []])
            ->push(['id' => 'playlist-item-1'])
            ->push(['id' => 'playlist-item-2'])
            ->push(['id' => 'playlist-item-3'])
            ->push(['items' => $this->youtubeItems()])
            ->push(['items' => $this->youtubeItems()])
            ->push([], 500)
            ->push([], 204)
            ->push([], 204)
            ->push(['items' => []]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
    }

    public function test_cleanup_deletes_known_insert_ids_when_bounded_scans_are_temporarily_empty(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['items' => [['id' => 'stable-account']]])
            ->push(['items' => [[
                'snippet' => ['channelId' => 'stable-account'],
                'status' => ['privacyStatus' => 'private'],
            ]]])
            ->push(['items' => []])
            ->push(['id' => 'playlist-item-1'])
            ->push(['id' => 'playlist-item-2'])
            ->push(['id' => 'playlist-item-3'])
            ->push(['items' => $this->youtubeItems()])
            ->push(['items' => []])
            ->push([], 204)
            ->push([], 204)
            ->push([], 204)
            ->push(['items' => []]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeResult::class, $result);
        $deleteUrls = Http::recorded()
            ->filter(static fn (array $record): bool => $record[0]->method() === 'DELETE')
            ->map(static fn (array $record): string => $record[0]->url())
            ->values()
            ->all();
        $this->assertSame([
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-1',
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-2',
            'https://www.googleapis.com/youtube/v3/playlistItems?id=playlist-item-3',
        ], $deleteUrls);
    }

    private function probe(): YouTubeProbe
    {
        return new YouTubeProbe(new RefreshTokenRotationSink($this->temporaryPath()));
    }

    private function technical(): TechnicalConfiguration
    {
        $configuration = TechnicalConfiguration::fromArray('youtube', [
            'client_id' => 'client-canary',
            'client_secret' => 'secret-canary',
            'refresh_token' => 'refresh-canary',
            'expected_account_id' => 'stable-account',
            'account_id' => 'stable-account',
            'scopes' => implode(' ', PlatformAccessProtocol::SCOPES['youtube']),
        ]);
        $this->assertInstanceOf(TechnicalConfiguration::class, $configuration);

        return $configuration;
    }

    private function tester_session(): TesterSession
    {
        $session = TesterSession::fromJson('youtube', json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => 'youtube',
            'principal' => 'tester',
            'client_id' => 'client-canary',
            'client_secret' => 'secret-canary',
            'refresh_token' => 'refresh-canary',
            'expected_account_id' => 'stable-account',
            'item_uris' => ['A1234567890', 'B1234567890', 'C1234567890'],
            'playlist_id' => 'fixture-playlist-canary',
        ], JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(TesterSession::class, $session);

        return $session;
    }

    private function refreshPayload(): array
    {
        return [
            'access_token' => 'access-canary',
            'scope' => PlatformAccessProtocol::SCOPES['youtube'][0],
        ];
    }

    private function youtubeItems(): array
    {
        return array_map(
            static fn (string $videoId, int $index): array => [
                'id' => 'playlist-item-'.($index + 1),
                'snippet' => ['resourceId' => ['videoId' => $videoId]],
            ],
            $this->tester_session()->itemUris,
            array_keys($this->tester_session()->itemUris),
        );
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().'/music-map-youtube-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
