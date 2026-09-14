<?php

namespace Tests\Unit\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\Providers\YouTubePlaylistReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubePlaylistReaderTest extends TestCase
{
    #[DataProvider('acceptedItemCounts')]
    public function test_it_reads_one_through_twenty_ordered_items_with_two_bounded_requests(int $count): void
    {
        Http::fakeSequence()
            ->push($this->metadata($count))
            ->push($this->items($count));

        $result = $this->reader()->read($this->reference());

        $this->assertInstanceOf(PlaylistSnapshot::class, $result);
        $this->assertCount($count, $result->items);
        $this->assertSame(range(0, $count - 1), array_column($result->items, 'position'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.googleapis.com/youtube/v3/playlists?part=snippet%2CcontentDetails&id=PL_canary&key=api-key-canary');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.googleapis.com/youtube/v3/playlistItems?part=id%2Csnippet%2Cstatus&playlistId=PL_canary&maxResults=21&key=api-key-canary');
    }

    public static function acceptedItemCounts(): array
    {
        return [[1], [20]];
    }

    public function test_it_returns_an_empty_snapshot_for_a_consistent_zero_item_playlist(): void
    {
        Http::fakeSequence()
            ->push($this->metadata(0))
            ->push($this->items(0));

        $result = $this->reader()->read($this->reference());

        $this->assertInstanceOf(PlaylistSnapshot::class, $result);
        $this->assertSame([], $result->items);
        Http::assertSentCount(2);
    }

    public function test_it_preserves_duplicates_and_normalizes_private_or_deleted_items_as_placeholders(): void
    {
        $items = $this->items(3);
        $items['items'][1]['snippet']['resourceId']['videoId'] = 'video-0';
        $items['items'][2]['snippet']['title'] = 'Private video';
        unset($items['items'][2]['snippet']['resourceId']['videoId']);
        Http::fakeSequence()->push($this->metadata(3))->push($items);

        $result = $this->reader()->read($this->reference());

        $this->assertInstanceOf(PlaylistSnapshot::class, $result);
        $this->assertSame(['video-0', 'video-0', null], array_column($result->items, 'catalogId'));
        $this->assertFalse($result->items[2]->isAvailable);
        $this->assertNull($result->items[2]->title);
    }

    public function test_it_refuses_the_complete_snapshot_when_a_twenty_first_item_exists(): void
    {
        Http::fakeSequence()->push($this->metadata(21))->push($this->items(21));

        $this->assertSame(ImportFailureCode::TooManyItems, $this->reader()->read($this->reference()));
    }

    #[DataProvider('providerFailures')]
    public function test_it_maps_bounded_provider_failures(int $status, array $body, ImportFailureCode $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->assertSame($expected, $this->reader()->read($this->reference()));
    }

    public static function providerFailures(): array
    {
        return [
            'forbidden' => [403, ['error' => ['errors' => [['reason' => 'forbidden']]]], ImportFailureCode::PlaylistUnavailable],
            'missing' => [404, [], ImportFailureCode::PlaylistNotFound],
            'rate status' => [429, [], ImportFailureCode::RateLimited],
            'rate reason' => [403, ['error' => ['errors' => [['reason' => 'rateLimitExceeded']]]], ImportFailureCode::RateLimited],
            'quota' => [403, ['error' => ['errors' => [['reason' => 'quotaExceeded']]]], ImportFailureCode::QuotaLimited],
            'server' => [503, [], ImportFailureCode::ProviderUnavailable],
        ];
    }

    public function test_it_maps_transport_failure_without_exposing_the_exception(): void
    {
        Http::fakeSequence()->pushFailedConnection('sensitive provider context');

        $result = $this->reader()->read($this->reference());

        $this->assertSame(ImportFailureCode::ProviderUnavailable, $result);
        $this->assertStringNotContainsString('sensitive', $result->value);
    }

    public function test_it_rejects_a_malformed_http_200_response_as_invalid_response(): void
    {
        Http::fakeSequence()->push($this->metadata(1))->push(['items' => 'invalid']);

        $this->assertSame(ImportFailureCode::InvalidResponse, $this->reader()->read($this->reference()));
    }

    public function test_it_maps_an_unsupported_resource_kind_to_the_closed_item_failure(): void
    {
        Http::fakeSequence()->push($this->metadata(1))->push($this->items(1, 'youtube#channel'));

        $this->assertSame(ImportFailureCode::UnsupportedItem, $this->reader()->read($this->reference()));
    }

    private function reader(): YouTubePlaylistReader
    {
        return new YouTubePlaylistReader('api-key-canary');
    }

    private function reference(): PlaylistReference
    {
        return new PlaylistReference(
            StreamingProvider::YouTube,
            'PL_canary',
            'https://www.youtube.com/playlist?list=PL_canary',
        );
    }

    private function metadata(int $count): array
    {
        return ['items' => [[
            'id' => 'PL_canary',
            'etag' => 'revision-canary',
            'snippet' => [
                'title' => 'Canary playlist',
                'description' => 'Canary description',
                'channelId' => 'channel-canary',
            ],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    private function items(int $count, string $kind = 'youtube#video'): array
    {
        $items = [];

        for ($position = 0; $position < $count; $position++) {
            $items[] = [
                'id' => "occurrence-{$position}",
                'snippet' => [
                    'position' => $position,
                    'title' => "Canary item {$position}",
                    'videoOwnerChannelTitle' => 'Canary creator',
                    'resourceId' => [
                        'kind' => $kind,
                        'videoId' => "video-{$position}",
                    ],
                ],
                'status' => ['privacyStatus' => 'public'],
            ];
        }

        return [
            'items' => $items,
            'pageInfo' => ['totalResults' => $count, 'resultsPerPage' => 21],
        ];
    }
}
