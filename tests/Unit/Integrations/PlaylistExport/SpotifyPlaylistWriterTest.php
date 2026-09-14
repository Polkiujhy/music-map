<?php

namespace Tests\Unit\Integrations\PlaylistExport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\Data\CreateRecoveryResult;
use App\Integrations\PlaylistExport\Data\ExportPlaylistDefinition;
use App\Integrations\PlaylistExport\Data\PlaylistWriteResult;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\PlaylistExport\ProviderPlaylistUrl;
use App\Integrations\PlaylistExport\Providers\SpotifyPlaylistWriter;
use App\Jobs\ExecuteExportOperation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotifyPlaylistWriterTest extends TestCase
{
    public function test_export_job_timing_preserves_the_required_safety_order(): void
    {
        $job = new ExecuteExportOperation(1);

        $this->assertSame(450, $job->timeout);
        $this->assertSame(480, $job->middleware()[0]->expiresAfter);
        $this->assertSame(510, (int) config('queue.connections.database.retry_after'));
        $this->assertLessThan($job->middleware()[0]->expiresAfter, $job->timeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->middleware()[0]->expiresAfter);
    }

    private const PLAYLIST_ID = '0123456789abcdefghijkl';

    private const OTHER_ID = 'zyxwvutsrqponmlkjihgfe';

    public function test_it_creates_a_private_non_collaborative_playlist_with_the_exact_marker(): void
    {
        Http::fake(['*' => Http::response([
            'id' => self::PLAYLIST_ID,
            'snapshot_id' => 'revision-1',
            'external_urls' => ['spotify' => 'javascript:alert(1)'],
        ], 201)]);
        $guard = $this->guard();

        $result = $this->writer()->create('token-canary', $this->definition(), $guard);

        $this->assertInstanceOf(PlaylistWriteResult::class, $result);
        $this->assertTrue($result->succeeded());
        $this->assertSame(self::PLAYLIST_ID, $result->providerId);
        $this->assertSame(1, $guard->calls);
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.spotify.com/v1/me/playlists'
                && $request->hasHeader('Authorization', 'Bearer token-canary')
                && $request->data() === [
                    'name' => 'Export canary',
                    'description' => "Frozen description\n\n[music-map:operation-canary]",
                    'public' => false,
                    'collaborative' => false,
                ];
        });
        $this->assertSame(
            'https://open.spotify.com/playlist/'.self::PLAYLIST_ID,
            ProviderPlaylistUrl::fromId(StreamingProvider::Spotify, $result->providerId),
        );
    }

    #[DataProvider('itemCounts')]
    public function test_it_replaces_zero_one_or_twenty_items_through_the_current_items_endpoint(int $count): void
    {
        $items = $this->spotifyUris($count);
        Http::fakeSequence()->push([], 200)->push(['snapshot_id' => 'revision-2']);
        $guard = $this->guard();

        $result = $this->writer()->replace('token-canary', $this->definition(self::PLAYLIST_ID, $items), $guard);

        $this->assertTrue($result->succeeded());
        $this->assertSame($items, $result->items);
        $this->assertSame(2, $guard->calls);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.spotify.com/v1/playlists/'.self::PLAYLIST_ID.'/items'
            && $request->data() === ['uris' => $items]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tracks'));
    }

    public static function itemCounts(): array
    {
        return [[0], [1], [20]];
    }

    public function test_guard_refusal_stops_the_remaining_mutation_plan(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $guard = $this->guard([null, PlaylistWriteFailure::ReconnectRequired]);

        $result = $this->writer()->replace('token-canary', $this->definition(self::PLAYLIST_ID), $guard);

        $this->assertSame(PlaylistWriteFailure::ReconnectRequired, $result->failure);
        Http::assertSentCount(1);
    }

    public function test_it_inspects_the_owned_private_target_and_returns_only_validated_ids(): void
    {
        Http::fakeSequence()
            ->push([
                'id' => self::PLAYLIST_ID,
                'owner' => ['id' => 'account-canary'],
                'description' => "Frozen description\n\n[music-map:operation-canary]",
                'public' => false,
                'collaborative' => false,
                'snapshot_id' => 'revision-1',
                'external_urls' => ['spotify' => 'https://attacker.example/playlist'],
            ])
            ->push([
                'items' => [['item' => ['uri' => $this->spotifyUris(1)[0]]]],
                'total' => 1,
                'next' => null,
            ]);

        $result = $this->writer()->inspect('token-canary', $this->definition(self::PLAYLIST_ID));

        $this->assertTrue($result->succeeded());
        $this->assertSame($this->spotifyUris(1), $result->items);
        $this->assertSame('https://open.spotify.com/playlist/'.self::PLAYLIST_ID, ProviderPlaylistUrl::fromId(
            StreamingProvider::Spotify,
            $result->providerId,
        ));
    }

    public function test_one_exact_owned_marker_recovers_the_id_without_a_mutation(): void
    {
        Http::fake(['*' => Http::response($this->scanPage([
            $this->scanItem(self::PLAYLIST_ID),
            $this->scanItem(self::OTHER_ID, 'other-account'),
        ], 2))]);

        $result = $this->writer()->recover('token-canary', $this->definition());

        $this->assertInstanceOf(CreateRecoveryResult::class, $result);
        $this->assertTrue($result->succeeded());
        $this->assertSame(self::PLAYLIST_ID, $result->providerId);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.spotify.com/v1/me/playlists?limit=50&offset=0');
    }

    #[DataProvider('ambiguousMarkerCounts')]
    public function test_zero_or_multiple_marker_hits_are_ambiguous_and_never_create(int $matches): void
    {
        $items = [];
        foreach (array_slice([self::PLAYLIST_ID, self::OTHER_ID], 0, $matches) as $id) {
            $items[] = $this->scanItem($id);
        }
        Http::fake(['*' => Http::response($this->scanPage($items, count($items)))]);

        $result = $this->writer()->recover('token-canary', $this->definition());

        $this->assertSame(PlaylistWriteFailure::AmbiguousCreate, $result->failure);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public static function ambiguousMarkerCounts(): array
    {
        return [[0], [2]];
    }

    public function test_twenty_full_pages_are_scanned_but_a_page_twenty_one_is_never_requested(): void
    {
        $sequence = Http::fakeSequence();
        for ($page = 0; $page < 20; $page++) {
            $items = [];
            for ($index = 0; $index < 50; $index++) {
                $numeric = str_pad((string) (($page * 50) + $index), 22, '0', STR_PAD_LEFT);
                $items[] = $this->scanItem($numeric, 'other-account', 'other description');
            }
            if ($page === 19) {
                $items[49] = $this->scanItem(self::PLAYLIST_ID);
            }
            $sequence->push($this->scanPage($items, 1000, $page === 19 ? null : 'next'));
        }

        $result = $this->writer()->recover('token-canary', $this->definition());

        $this->assertTrue($result->succeeded());
        $this->assertSame(self::PLAYLIST_ID, $result->providerId);
        Http::assertSentCount(20);
    }

    public function test_a_twenty_first_page_or_elapsed_budget_is_an_incomplete_scan_without_mutation(): void
    {
        $sequence = Http::fakeSequence();
        for ($page = 0; $page < 20; $page++) {
            $items = [];
            for ($index = 0; $index < 50; $index++) {
                $items[] = $this->scanItem(str_pad((string) (($page * 50) + $index), 22, '0', STR_PAD_LEFT), 'other-account', 'other');
            }
            $sequence->push($this->scanPage($items, 1000, 'next'));
        }

        $result = $this->writer()->recover('token-canary', $this->definition());
        $this->assertSame(PlaylistWriteFailure::RecoveryScanIncomplete, $result->failure);
        Http::assertSentCount(20);

        Http::fake(['*' => Http::response($this->scanPage([], 0))]);
        $ticks = [0.0, 361.0];
        $timed = new SpotifyPlaylistWriter(static function () use (&$ticks): float {
            return array_shift($ticks) ?? 361.0;
        });
        $this->assertSame(
            PlaylistWriteFailure::RecoveryScanIncomplete,
            $timed->recover('token-canary', $this->definition())->failure,
        );
    }

    #[DataProvider('providerFailures')]
    public function test_it_maps_provider_and_transport_failures(int $status, PlaylistWriteFailure $expected): void
    {
        Http::fake(['*' => Http::response([], $status)]);

        $result = $this->writer()->create('token-canary', $this->definition(), $this->guard());

        $this->assertSame($expected, $result->failure);
        Http::assertSentCount(1);
    }

    public static function providerFailures(): array
    {
        return [
            [401, PlaylistWriteFailure::ReconnectRequired],
            [403, PlaylistWriteFailure::AccessDenied],
            [404, PlaylistWriteFailure::InvalidResponse],
            [429, PlaylistWriteFailure::RateLimited],
            [500, PlaylistWriteFailure::TemporaryFailure],
        ];
    }

    public function test_ambiguous_create_transport_failure_is_not_retried(): void
    {
        Http::fakeSequence()->pushFailedConnection('secret transport detail');

        $result = $this->writer()->create('token-canary', $this->definition(), $this->guard());

        $this->assertSame(PlaylistWriteFailure::AmbiguousCreate, $result->failure);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidProviderIds')]
    public function test_canonical_url_rejects_hostile_or_non_canonical_ids(string $id): void
    {
        $this->assertNull(ProviderPlaylistUrl::fromId(StreamingProvider::Spotify, $id));
    }

    public static function invalidProviderIds(): array
    {
        return [
            ['short'],
            [str_repeat('a', 23)],
            ["0123456789abcdefghijk\n"],
            ['https://user:pass@open.spotify.com:8443/playlist/0123456789abcdefghijkl#fragment'],
            ['javascript:alert(123456)'],
        ];
    }

    private function writer(): SpotifyPlaylistWriter
    {
        return new SpotifyPlaylistWriter;
    }

    private function definition(?string $targetId = null, ?array $items = null): ExportPlaylistDefinition
    {
        return new ExportPlaylistDefinition(
            StreamingProvider::Spotify,
            'account-canary',
            $targetId,
            'Export canary',
            'Frozen description',
            '[music-map:operation-canary]',
            'private',
            $items ?? $this->spotifyUris(1),
        );
    }

    private function spotifyUris(int $count): array
    {
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            $items[] = 'spotify:track:'.str_pad((string) $index, 22, '0', STR_PAD_LEFT);
        }

        return $items;
    }

    private function scanItem(string $id, string $owner = 'account-canary', ?string $description = null): array
    {
        return [
            'id' => $id,
            'owner' => ['id' => $owner],
            'description' => $description ?? "Frozen description\n\n[music-map:operation-canary]",
            'public' => false,
        ];
    }

    private function scanPage(array $items, int $total, ?string $next = null): array
    {
        return ['items' => $items, 'total' => $total, 'next' => $next];
    }

    private function guard(array $failures = []): ExportMutationGuard
    {
        return new class($failures) implements ExportMutationGuard
        {
            public int $calls = 0;

            public function __construct(private array $failures) {}

            public function failure(): ?PlaylistWriteFailure
            {
                return $this->failures[$this->calls++] ?? null;
            }
        };
    }
}
