<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubeSourcePlaylistReaderTest extends TestCase
{
    public function test_it_reads_private_items_and_preserves_duplicate_video_and_provider_item_ids(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['items' => [[
                'id' => 'playlist-canary',
                'etag' => 'revision-canary',
                'snippet' => ['channelId' => 'channel-canary'],
                'contentDetails' => ['itemCount' => 2],
            ]]])
            ->push(['items' => [$this->item('occurrence-a', 0), $this->item('occurrence-b', 1)]]);

        $snapshot = (new YouTubeSourcePlaylistReader)->read('playlist-canary', new StreamingAccessContext(
            StreamingProvider::YouTube,
            'channel-canary',
            'access-canary',
        ));

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $snapshot);
        $this->assertSame(['video-canary', 'video-canary'], $snapshot->itemIdentifiers);
        $this->assertSame(['occurrence-a', 'occurrence-b'], $snapshot->providerItemIdentifiers);
        $this->assertSame('channel-canary', $snapshot->ownerAccountId);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer access-canary'));
    }

    public function test_it_preserves_deleted_and_private_positions_as_unavailable_placeholders(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['items' => [[
                'id' => 'playlist-canary',
                'etag' => 'revision-canary',
                'snippet' => ['channelId' => 'channel-canary'],
                'contentDetails' => ['itemCount' => 2],
            ]]])
            ->push(['items' => [
                $this->unavailableItem('deleted-occurrence', 0, 'Deleted video', null, 'public'),
                $this->unavailableItem('private-occurrence', 1, 'Private video', 'private-video', 'private'),
            ]]);

        $snapshot = (new YouTubeSourcePlaylistReader)->read('playlist-canary', new StreamingAccessContext(
            StreamingProvider::YouTube,
            'channel-canary',
            'access-canary',
        ));

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $snapshot);
        $this->assertSame([null, null], $snapshot->itemIdentifiers);
        $this->assertSame(['deleted-occurrence', 'private-occurrence'], $snapshot->providerItemIdentifiers);
        $this->assertSame([false, false], array_column($snapshot->items, 'is_available'));
        $this->assertSame([null, null], array_column($snapshot->items, 'catalog_uri'));
    }

    /** @return array<string, array{string, SourceSyncFailure}> */
    public static function limitReasons(): array
    {
        return [
            'quota exceeded' => ['quotaExceeded', SourceSyncFailure::QuotaLimited],
            'daily limit exceeded' => ['dailyLimitExceeded', SourceSyncFailure::QuotaLimited],
            'rate limit exceeded' => ['rateLimitExceeded', SourceSyncFailure::RateLimited],
            'user rate limit exceeded' => ['userRateLimitExceeded', SourceSyncFailure::RateLimited],
        ];
    }

    #[DataProvider('limitReasons')]
    public function test_it_distinguishes_youtube_quota_and_rate_limit_reasons(
        string $reason,
        SourceSyncFailure $expected,
    ): void {
        Http::preventStrayRequests();
        Http::fakeSequence()->push([
            'error' => ['errors' => [['reason' => $reason]]],
        ], 403);

        $result = (new YouTubeSourcePlaylistReader)->read('playlist-canary', new StreamingAccessContext(
            StreamingProvider::YouTube,
            'channel-canary',
            'access-canary',
        ));

        $this->assertSame($expected, $result);
    }

    private function item(string $id, int $position): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'position' => $position,
                'title' => 'Video',
                'videoOwnerChannelTitle' => 'Creator',
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => 'video-canary'],
            ],
            'status' => ['privacyStatus' => 'public'],
        ];
    }

    private function unavailableItem(string $id, int $position, string $title, ?string $videoId, string $privacy): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'position' => $position,
                'title' => $title,
                'resourceId' => array_filter([
                    'kind' => 'youtube#video',
                    'videoId' => $videoId,
                ]),
            ],
            'status' => ['privacyStatus' => $privacy],
        ];
    }
}
