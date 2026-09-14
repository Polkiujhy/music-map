<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Support\Facades\Http;
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

    private function item(string $id, int $position): array
    {
        return [
            'id' => $id,
            'snippet' => [
                'position' => $position,
                'title' => 'Video',
                'videoOwnerChannelTitle' => 'Creator',
                'resourceId' => ['videoId' => 'video-canary'],
            ],
            'status' => ['privacyStatus' => 'private'],
        ];
    }
}
