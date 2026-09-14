<?php

namespace Tests\Unit\Integrations\PlaylistExport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\Data\ExportPlaylistDefinition;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\PlaylistExport\ProviderPlaylistUrl;
use App\Integrations\PlaylistExport\Providers\YouTubePlaylistWriter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubePlaylistWriterTest extends TestCase
{
    private const PLAYLIST_ID = 'PL0123456789abcdef';

    private const OTHER_ID = 'PLfedcba9876543210';

    public function test_it_creates_an_unlisted_playlist_with_the_exact_marker(): void
    {
        Http::fake(['*' => Http::response([
            'id' => self::PLAYLIST_ID,
            'etag' => 'revision-1',
            'player' => ['embedHtml' => '<a href="javascript:alert(1)">hostile</a>'],
        ], 201)]);
        $guard = $this->guard();

        $result = $this->writer()->create('token-canary', $this->definition(), $guard);

        $this->assertTrue($result->succeeded());
        $this->assertSame(self::PLAYLIST_ID, $result->providerId);
        $this->assertSame(1, $guard->calls);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/playlists?')
                && ($data['snippet']['title'] ?? null) === 'Export canary'
                && ($data['snippet']['description'] ?? null) === "Frozen description\n\n[music-map:operation-canary]"
                && ($data['status']['privacyStatus'] ?? null) === 'unlisted';
        });
        $this->assertSame(
            'https://www.youtube.com/playlist?list='.self::PLAYLIST_ID,
            ProviderPlaylistUrl::fromId(StreamingProvider::YouTube, $result->providerId),
        );
    }

    public function test_it_uses_one_move_for_a_rotation_and_verifies_the_final_order(): void
    {
        [$a, $b, $c] = $this->videoIds(3);
        Http::fakeSequence()
            ->push($this->itemPage([$a, $b, $c]))
            ->push(['etag' => 'revision-2'])
            ->push([])
            ->push($this->itemPage([$b, $c, $a]));
        $guard = $this->guard();

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, [$b, $c, $a]),
            $guard,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([$b, $c, $a], $result->items);
        $this->assertSame(2, $guard->calls, 'metadata plus one minimal move');
        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/playlistItems?')
                && ($data['id'] ?? null) === 'occurrence-0'
                && ($data['snippet']['position'] ?? null) === 2;
        });
    }

    public function test_it_calculates_minimal_deletions_and_inserts_without_rebuilding_the_playlist(): void
    {
        [$a, $b, $c, $d] = $this->videoIds(4);
        Http::fakeSequence()
            ->push($this->itemPage([$a, $b, $c]))
            ->push(['etag' => 'revision-3'])
            ->push([], 204)
            ->push([], 204)
            ->push(['id' => 'inserted-occurrence'])
            ->push($this->itemPage([$a, $d], ['occurrence-0', 'inserted-occurrence']));
        $guard = $this->guard();

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, [$a, $d]),
            $guard,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(4, $guard->calls, 'metadata, two necessary deletes and one insert');
        Http::assertSentCount(6);
    }

    public function test_duplicates_remain_distinct_occurrences_when_supported(): void
    {
        [$a] = $this->videoIds(1);
        Http::fakeSequence()
            ->push($this->itemPage([$a]))
            ->push(['etag' => 'revision-4'])
            ->push(['id' => 'second-occurrence'])
            ->push($this->itemPage([$a, $a], ['occurrence-0', 'second-occurrence']));

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, [$a, $a]),
            $this->guard(),
        );

        $this->assertTrue($result->succeeded());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/playlistItems?'));
    }

    public function test_an_account_without_verified_duplicate_support_refuses_before_mutation(): void
    {
        Http::fake();
        [$a] = $this->videoIds(1);

        $result = (new YouTubePlaylistWriter(false))->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, [$a, $a]),
            $this->guard(),
        );

        $this->assertSame(PlaylistWriteFailure::UnsupportedDuplicate, $result->failure);
        Http::assertNothingSent();
    }

    public function test_guard_refusal_stops_all_later_mutations(): void
    {
        [$a, $b] = $this->videoIds(2);
        Http::fakeSequence()->push($this->itemPage([$a]))->push(['etag' => 'revision']);
        $guard = $this->guard([null, PlaylistWriteFailure::ReconnectRequired]);

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, [$a, $b]),
            $guard,
        );

        $this->assertSame(PlaylistWriteFailure::ReconnectRequired, $result->failure);
        Http::assertSentCount(2);
    }

    public function test_playlist_not_found_retires_the_target_during_item_inspection(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['errors' => [['reason' => 'playlistNotFound']]],
        ], 404)]);

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID),
            $this->guard(),
        );

        $this->assertSame(PlaylistWriteFailure::TargetDeleted, $result->failure);
        Http::assertSentCount(1);
    }

    public function test_video_not_found_does_not_retire_the_target_during_insert(): void
    {
        Http::fakeSequence()
            ->push($this->itemPage([]))
            ->push(['etag' => 'revision'])
            ->push(['error' => ['errors' => [['reason' => 'videoNotFound']]]], 404);

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID),
            $this->guard(),
        );

        $this->assertSame(PlaylistWriteFailure::InvalidResponse, $result->failure);
        Http::assertSentCount(3);
    }

    public function test_playlist_item_not_found_does_not_retire_the_target_during_delete(): void
    {
        [$video] = $this->videoIds(1);
        Http::fakeSequence()
            ->push($this->itemPage([$video]))
            ->push(['etag' => 'revision'])
            ->push(['error' => ['errors' => [['reason' => 'playlistItemNotFound']]]], 404);

        $result = $this->writer()->replace(
            'token-canary',
            $this->definition(self::PLAYLIST_ID, []),
            $this->guard(),
        );

        $this->assertSame(PlaylistWriteFailure::InvalidResponse, $result->failure);
        Http::assertSentCount(3);
    }

    public function test_it_inspects_an_owned_unlisted_playlist_and_ignores_provider_links(): void
    {
        [$a] = $this->videoIds(1);
        Http::fakeSequence()
            ->push(['items' => [[
                'id' => self::PLAYLIST_ID,
                'etag' => 'revision-1',
                'snippet' => [
                    'channelId' => 'channel-canary',
                    'description' => "Frozen description\n\n[music-map:operation-canary]",
                ],
                'status' => ['privacyStatus' => 'unlisted'],
                'player' => ['embedHtml' => 'javascript:alert(1)'],
            ]]])
            ->push($this->itemPage([$a]));

        $result = $this->writer()->inspect('token-canary', $this->definition(self::PLAYLIST_ID));

        $this->assertTrue($result->succeeded());
        $this->assertSame([$a], $result->items);
        $this->assertSame('https://www.youtube.com/playlist?list='.self::PLAYLIST_ID, ProviderPlaylistUrl::fromId(
            StreamingProvider::YouTube,
            $result->providerId,
        ));
    }

    public function test_recovery_paginates_mine_and_accepts_one_exact_owned_marker(): void
    {
        Http::fakeSequence()
            ->push($this->scanPage([$this->scanItem(self::OTHER_ID, 'other')], 2, 'page-2'))
            ->push($this->scanPage([$this->scanItem(self::PLAYLIST_ID)], 2));

        $result = $this->writer()->recover('token-canary', $this->definition());

        $this->assertTrue($result->succeeded());
        $this->assertSame(self::PLAYLIST_ID, $result->providerId);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'mine=true')
            && str_contains($request->url(), 'maxResults=50'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pageToken=page-2'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    #[DataProvider('ambiguousMarkerCounts')]
    public function test_zero_or_multiple_marker_hits_never_trigger_another_create(int $matches): void
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

    public function test_page_twenty_one_and_elapsed_budget_fail_as_incomplete_without_mutation(): void
    {
        $sequence = Http::fakeSequence();
        for ($page = 0; $page < 20; $page++) {
            $items = [];
            for ($index = 0; $index < 50; $index++) {
                $items[] = $this->scanItem('PL'.str_pad((string) (($page * 50) + $index), 15, '0', STR_PAD_LEFT), 'other');
            }
            $sequence->push($this->scanPage($items, 1000, 'next-page'));
        }

        $result = $this->writer()->recover('token-canary', $this->definition());
        $this->assertSame(PlaylistWriteFailure::RecoveryScanIncomplete, $result->failure);
        Http::assertSentCount(20);

        Http::fake(['*' => Http::response($this->scanPage([], 0))]);
        $ticks = [0.0, 361.0];
        $timed = new YouTubePlaylistWriter(true, static function () use (&$ticks): float {
            return array_shift($ticks) ?? 361.0;
        });
        $this->assertSame(
            PlaylistWriteFailure::RecoveryScanIncomplete,
            $timed->recover('token-canary', $this->definition())->failure,
        );
    }

    #[DataProvider('providerFailures')]
    public function test_it_maps_provider_failures(int $status, array $body, PlaylistWriteFailure $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $result = $this->writer()->create('token-canary', $this->definition(), $this->guard());

        $this->assertSame($expected, $result->failure);
        Http::assertSentCount(1);
    }

    public static function providerFailures(): array
    {
        return [
            [401, [], PlaylistWriteFailure::ReconnectRequired],
            [403, [], PlaylistWriteFailure::AccessDenied],
            [403, ['error' => ['errors' => [['reason' => 'quotaExceeded']]]], PlaylistWriteFailure::QuotaLimited],
            [429, [], PlaylistWriteFailure::RateLimited],
            [500, [], PlaylistWriteFailure::TemporaryFailure],
        ];
    }

    public function test_transport_failure_after_create_is_ambiguous_and_is_not_retried(): void
    {
        Http::fakeSequence()->pushFailedConnection('secret transport detail');

        $result = $this->writer()->create('token-canary', $this->definition(), $this->guard());

        $this->assertSame(PlaylistWriteFailure::AmbiguousCreate, $result->failure);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidProviderIds')]
    public function test_canonical_urls_reject_hostile_or_non_canonical_ids(string $id): void
    {
        $this->assertNull(ProviderPlaylistUrl::fromId(StreamingProvider::YouTube, $id));
    }

    public static function invalidProviderIds(): array
    {
        return [
            ['short'],
            [str_repeat('a', 65)],
            ["PL0123456789\n"],
            ['https://user:pass@youtube.com:8443/playlist?list=PL0123456789abcdef#fragment'],
            ['javascript:alert(123456)'],
        ];
    }

    private function writer(): YouTubePlaylistWriter
    {
        return new YouTubePlaylistWriter;
    }

    private function definition(?string $targetId = null, ?array $items = null): ExportPlaylistDefinition
    {
        return new ExportPlaylistDefinition(
            StreamingProvider::YouTube,
            'channel-canary',
            $targetId,
            'Export canary',
            'Frozen description',
            '[music-map:operation-canary]',
            'unlisted',
            $items ?? $this->videoIds(1),
        );
    }

    private function videoIds(int $count): array
    {
        $items = [];
        for ($index = 0; $index < $count; $index++) {
            $items[] = 'vid'.str_pad((string) $index, 8, '0', STR_PAD_LEFT);
        }

        return $items;
    }

    private function itemPage(array $videoIds, ?array $occurrenceIds = null): array
    {
        $items = [];
        foreach ($videoIds as $position => $videoId) {
            $items[] = [
                'id' => $occurrenceIds[$position] ?? "occurrence-{$position}",
                'snippet' => [
                    'position' => $position,
                    'resourceId' => ['videoId' => $videoId],
                ],
            ];
        }

        return ['items' => $items, 'pageInfo' => ['totalResults' => count($items)]];
    }

    private function scanItem(string $id, string $description = "Frozen description\n\n[music-map:operation-canary]"): array
    {
        return [
            'id' => $id,
            'snippet' => ['channelId' => 'channel-canary', 'description' => $description],
            'status' => ['privacyStatus' => 'unlisted'],
        ];
    }

    private function scanPage(array $items, int $total, ?string $next = null): array
    {
        $payload = ['items' => $items, 'pageInfo' => ['totalResults' => $total]];
        if ($next !== null) {
            $payload['nextPageToken'] = $next;
        }

        return $payload;
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
