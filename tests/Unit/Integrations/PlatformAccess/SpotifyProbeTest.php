<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeResult;
use App\Integrations\PlatformAccess\RefreshTokenRotationSink;
use App\Integrations\PlatformAccess\SpotifyProbe;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SpotifyProbeTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        Sleep::fake(false);

        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_technical_refreshes_and_reads_identity_without_mutation(): void
    {
        Log::spy();
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response($this->refreshPayload()),
            'api.spotify.com/v1/me' => Http::response([
                'account_id' => 'stable-account',
                'id' => 'ephemeral-user',
                'product' => 'free',
            ]),
        ]);

        $result = $this->probe()->probe($this->technical());

        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame(PlatformAccessProtocol::CAPABILITIES['technical'], $result->capabilities);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://accounts.spotify.com/api/token'
            && $request->method() === 'POST'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-canary'
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Basic '));
        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE'], true));
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_tester_replaces_exact_items_verifies_order_and_restores_empty_fixture(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push(['snapshot_id' => 'ignored'])
            ->push(['items' => $this->spotifyItems()])
            ->push(['snapshot_id' => 'ignored-again'])
            ->push($this->cleanupPlaylist('ignored-again'));

        $session = $this->tester_session();
        $result = $this->probe()->probe($session);

        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertSame(PlatformAccessProtocol::CAPABILITIES['tester'], $result->capabilities);
        Http::assertSentCount(8);

        $putBodies = [];
        Http::assertSent(function (Request $request) use (&$putBodies): bool {
            if ($request->method() === 'PUT') {
                $putBodies[] = $request->data();
            }

            return true;
        });
        $this->assertSame([
            ['uris' => $session->itemUris],
            ['uris' => []],
        ], $putBodies);
    }

    public function test_invalid_fixture_stops_before_mutation(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => true, 'owner' => ['id' => 'ephemeral-user']]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('fixture-invalid', $result->category);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_cleanup_waits_for_the_empty_replacement_snapshot(): void
    {
        Sleep::fake();

        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push(['snapshot_id' => 'inserted'])
            ->push(['items' => $this->spotifyItems()])
            ->push(['snapshot_id' => 'cleared'])
            ->push($this->cleanupPlaylist('inserted', total: 3))
            ->push($this->cleanupPlaylist('cleared'));

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeResult::class, $result);
        Http::assertSentCount(9);
        $cleanupReplacements = 0;
        Http::assertSent(function (Request $request) use (&$cleanupReplacements): bool {
            if ($request->method() === 'PUT' && $request->data() === ['uris' => []]) {
                $cleanupReplacements++;
            }

            return true;
        });
        $this->assertSame(1, $cleanupReplacements);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.spotify.com/v1/playlists/fixture-playlist-canary?fields=snapshot_id%2Citems%28total%2Citems%2Cnext%29');
        Sleep::assertSleptTimes(1);
    }

    public function test_cleanup_requires_a_snapshot_from_the_empty_replacement(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push(['snapshot_id' => 'inserted'])
            ->push(['items' => $this->spotifyItems()])
            ->push([]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
        Http::assertSentCount(7);
        Sleep::assertNeverSlept();
    }

    public function test_cleanup_remains_failed_when_playlist_never_reads_empty(): void
    {
        Sleep::fake();

        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push(['snapshot_id' => 'inserted'])
            ->push(['items' => $this->spotifyItems()])
            ->push(['snapshot_id' => 'cleared'])
            ->push($this->cleanupPlaylist('cleared', total: 3))
            ->push($this->cleanupPlaylist('cleared', total: 3))
            ->push($this->cleanupPlaylist('cleared', total: 3))
            ->push($this->cleanupPlaylist('cleared', total: 3))
            ->push($this->cleanupPlaylist('cleared', total: 3))
            ->push($this->cleanupPlaylist('cleared', total: 3));

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
        Http::assertSentCount(13);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(2)->seconds(),
            Sleep::for(4)->seconds(),
            Sleep::for(8)->seconds(),
            Sleep::for(15)->seconds(),
        ]);
    }

    public function test_cleanup_rejects_an_empty_page_that_advertises_more_items(): void
    {
        Sleep::fake();

        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push(['snapshot_id' => 'inserted'])
            ->push(['items' => $this->spotifyItems()])
            ->push(['snapshot_id' => 'cleared'])
            ->push($this->cleanupPlaylist('cleared', next: 'https://api.spotify.com/v1/playlists/fixture-playlist-canary/items?offset=1&limit=1'));

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
        Http::assertSentCount(8);
        Sleep::assertNeverSlept();
    }

    public function test_ephemeral_profile_id_is_not_an_account_identity_fallback(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['id' => 'stable-account']);

        $result = $this->probe()->probe($this->technical());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('provider-response-invalid', $result->category);
        Http::assertSentCount(2);
    }

    public function test_missing_ephemeral_playlist_owner_is_fixture_invalid_without_mutation(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => []]);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('fixture-invalid', $result->category);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    public function test_cleanup_failure_overrides_an_earlier_provider_failure(): void
    {
        Http::fakeSequence()
            ->push($this->refreshPayload())
            ->push(['account_id' => 'stable-account', 'id' => 'ephemeral-user'])
            ->push(['public' => false, 'owner' => ['id' => 'ephemeral-user']])
            ->push(['items' => []])
            ->push([], 500)
            ->push([], 500);

        $result = $this->probe()->probe($this->tester_session());

        $this->assertInstanceOf(ProbeFailure::class, $result);
        $this->assertSame('cleanup-failed', $result->category);
        Http::assertSentCount(6);
    }

    public function test_replacement_token_is_written_before_the_access_token_is_used(): void
    {
        $path = $this->temporaryPath();
        $replacement = 'replacement-refresh-canary';

        Http::fake(function (Request $request) use ($path, $replacement) {
            if ($request->url() === 'https://accounts.spotify.com/api/token') {
                return Http::response($this->refreshPayload(['refresh_token' => $replacement]));
            }

            $this->assertFileExists($path);
            $this->assertStringContainsString($replacement, (string) file_get_contents($path));

            return Http::response(['account_id' => 'stable-account']);
        });

        $result = $this->probe($path)->probe($this->technical());

        $this->assertInstanceOf(ProbeResult::class, $result);
        $this->assertStringNotContainsString($replacement, $result->stdout());
        $this->assertStringNotContainsString($replacement, $result->stderr());
    }

    private function probe(?string $path = null): SpotifyProbe
    {
        return new SpotifyProbe(new RefreshTokenRotationSink($path ?? $this->temporaryPath()));
    }

    private function technical(): TechnicalConfiguration
    {
        $configuration = TechnicalConfiguration::fromArray('spotify', [
            'client_id' => 'client-canary',
            'client_secret' => 'secret-canary',
            'refresh_token' => 'refresh-canary',
            'expected_account_id' => 'stable-account',
            'account_id' => 'stable-account',
            'scopes' => implode(' ', PlatformAccessProtocol::SCOPES['spotify']),
        ]);
        $this->assertInstanceOf(TechnicalConfiguration::class, $configuration);

        return $configuration;
    }

    private function tester_session(): TesterSession
    {
        $session = TesterSession::fromJson('spotify', json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => 'spotify',
            'principal' => 'tester',
            'client_id' => 'client-canary',
            'client_secret' => 'secret-canary',
            'refresh_token' => 'refresh-canary',
            'expected_account_id' => 'stable-account',
            'item_uris' => [
                'spotify:track:0123456789ABCDEFGHIJKL',
                'spotify:track:123456789ABCDEFGHIJKLM',
                'spotify:track:23456789ABCDEFGHIJKLMN',
            ],
            'playlist_id' => 'fixture-playlist-canary',
        ], JSON_THROW_ON_ERROR));
        $this->assertInstanceOf(TesterSession::class, $session);

        return $session;
    }

    private function refreshPayload(array $extra = []): array
    {
        return [
            'access_token' => 'access-canary',
            'scope' => implode(' ', array_reverse(PlatformAccessProtocol::SCOPES['spotify'])),
            ...$extra,
        ];
    }

    private function spotifyItems(): array
    {
        return array_map(
            static fn (string $uri): array => ['item' => ['uri' => $uri]],
            $this->tester_session()->itemUris,
        );
    }

    private function cleanupPlaylist(string $snapshot, int $total = 0, ?string $next = null): array
    {
        return [
            'snapshot_id' => $snapshot,
            'items' => [
                'total' => $total,
                'items' => $total === 0 ? [] : array_fill(0, min($total, 20), []),
                'next' => $next,
            ],
        ];
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir().'/music-map-spotify-'.bin2hex(random_bytes(8));
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
