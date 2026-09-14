<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\YouTube\PlanYouTubePlaylistMutations;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\PlaylistSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeSourcePlaylistWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checkpoints_each_mutation_and_reaches_the_exact_desired_state(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push([], 204)
            ->push($this->metadata(0))
            ->push(['items' => []])
            ->push(['id' => 'item-b'])
            ->push($this->metadata(1))
            ->push(['items' => [$this->item('item-b', 'b', 0)]]);

        $run = PlaylistSyncRun::factory()->create(['checkpoint' => null]);
        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot(['a'], ['item-a']),
            [['catalog_id' => 'b']],
            $this->access(),
            $run,
        );

        $this->assertSame(['b'], $result->itemIdentifiers);
        $this->assertSame(2, $run->refresh()->checkpoint['index']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['snippet']['playlistId'] === 'playlist-canary'
            && $request['snippet']['resourceId']['videoId'] === 'b');
    }

    public function test_retry_from_the_after_state_does_not_repeat_an_insert(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $planner = new PlanYouTubePlaylistMutations;
        $mutation = $planner->handle($this->snapshot([], []), ['b'])[0];
        $run = PlaylistSyncRun::factory()->create(['checkpoint' => [
            'index' => 0,
            'mutations' => [$mutation->toArray()],
        ]]);

        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot(['b'], ['item-b']),
            [['catalog_id' => 'b']],
            $this->access(),
            $run,
        );

        $this->assertSame(['b'], $result->itemIdentifiers);
        Http::assertNothingSent();
    }

    public function test_it_removes_unavailable_source_positions_and_writes_the_available_bank_projection(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push([], 204)
            ->push($this->metadata(2))
            ->push(['items' => [$this->item('item-a', 'a', 0), $this->item('item-b', 'b', 1)]])
            ->push([], 200)
            ->push($this->metadata(2))
            ->push(['items' => [$this->item('item-b', 'b', 0), $this->item('item-a', 'a', 1)]]);

        $run = PlaylistSyncRun::factory()->create(['checkpoint' => null]);
        $desired = [
            ['catalog_id' => 'b', 'is_available' => true],
            ['catalog_id' => null, 'is_available' => false],
            ['catalog_id' => 'a', 'is_available' => true],
        ];
        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot(['a', null, 'b'], ['item-a', 'item-unavailable', 'item-b']),
            $desired,
            $this->access(),
            $run,
        );

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $result);
        $this->assertSame(['b', 'a'], $result->itemIdentifiers);
        $this->assertSame(2, $run->refresh()->checkpoint['index']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://www.googleapis.com/youtube/v3/playlistItems?id=item-unavailable');
    }

    public function test_it_maps_youtube_write_quota_responses_without_treating_them_as_forbidden(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push([
            'error' => ['errors' => [['reason' => 'quotaExceeded']]],
        ], 403);
        $run = PlaylistSyncRun::factory()->create(['checkpoint' => null]);

        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot(['a'], ['item-a']),
            [['catalog_id' => 'b']],
            $this->access(),
            $run,
        );

        $this->assertSame(SourceSyncFailure::QuotaLimited, $result);
    }

    private function writer(): YouTubeSourcePlaylistWriter
    {
        return new YouTubeSourcePlaylistWriter(new YouTubeSourcePlaylistReader, new PlanYouTubePlaylistMutations);
    }

    private function access(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::YouTube, 'owner', 'secret-canary');
    }

    /** @param list<string|null> $ids
     * @param  list<string>  $providerIds
     */
    private function snapshot(array $ids, array $providerIds): SourcePlaylistSnapshot
    {
        return new SourcePlaylistSnapshot($ids, providerItemIdentifiers: $providerIds);
    }

    private function metadata(int $count): array
    {
        return ['items' => [[
            'id' => 'playlist-canary', 'etag' => 'revision',
            'snippet' => ['channelId' => 'owner'],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    private function item(string $itemId, string $videoId, int $position): array
    {
        return ['id' => $itemId, 'snippet' => [
            'position' => $position, 'title' => 'Video',
            'videoOwnerChannelTitle' => 'Creator',
            'resourceId' => ['videoId' => $videoId],
        ]];
    }
}
