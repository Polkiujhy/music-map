<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotifySourcePlaylistWriterTest extends TestCase
{
    public function test_it_replaces_once_in_order_with_duplicates_and_confirms_the_revision(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['snapshot_id' => 'new-revision'])
            ->push(['id' => 'playlist-canary', 'owner' => ['id' => 'owner'], 'snapshot_id' => 'new-revision', 'tracks' => ['total' => 2]])
            ->push(['items' => [$this->item('a'), $this->item('a')], 'next' => null]);

        $result = $this->writer()->write('playlist-canary', new SourcePlaylistSnapshot([]), [
            ['catalog_id' => 'a', 'catalog_uri' => 'spotify:track:a'],
            ['catalog_id' => 'a', 'catalog_uri' => 'spotify:track:a'],
        ], $this->access());

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $result);
        $this->assertSame('new-revision', $result->providerRevision);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.spotify.com/v1/playlists/playlist-canary/items'
            && $request['uris'] === ['spotify:track:a', 'spotify:track:a']);
    }

    public function test_it_preserves_unavailable_bank_positions_while_writing_the_available_projection(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['snapshot_id' => 'new-revision'])
            ->push(['id' => 'playlist-canary', 'owner' => ['id' => 'owner'], 'snapshot_id' => 'new-revision', 'tracks' => ['total' => 2]])
            ->push(['items' => [$this->item('b'), $this->item('a')], 'next' => null]);

        $desired = [
            ['catalog_id' => 'b', 'catalog_uri' => 'spotify:track:b', 'is_available' => true],
            ['catalog_id' => null, 'catalog_uri' => null, 'is_available' => false],
            ['catalog_id' => 'a', 'catalog_uri' => 'spotify:track:a', 'is_available' => true],
        ];

        $result = $this->writer()->write('playlist-canary', new SourcePlaylistSnapshot(['a', null, 'b']), $desired, $this->access());

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $result);
        $this->assertSame(['b', 'a'], $result->itemIdentifiers);
        $this->assertNull($desired[1]['catalog_id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['uris'] === ['spotify:track:b', 'spotify:track:a']);
    }

    #[DataProvider('failures')]
    public function test_it_maps_stable_replace_failures(int $status, SourceSyncFailure $expected): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push([], $status);
        $result = $this->writer()->write('playlist-canary', new SourcePlaylistSnapshot([]), [], $this->access());
        $this->assertSame($expected, $result);
        Http::assertSentCount(1);
    }

    public static function failures(): array
    {
        return [[401, SourceSyncFailure::Unauthorized], [403, SourceSyncFailure::Forbidden],
            [404, SourceSyncFailure::NotFound], [429, SourceSyncFailure::RateLimited],
            [503, SourceSyncFailure::ProviderUnavailable]];
    }

    private function writer(): SpotifySourcePlaylistWriter
    {
        return new SpotifySourcePlaylistWriter(new SpotifySourcePlaylistReader);
    }

    private function access(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::Spotify, 'owner', 'secret-canary');
    }

    private function item(string $id): array
    {
        return ['item' => ['type' => 'track', 'id' => $id, 'uri' => "spotify:track:{$id}", 'name' => 'Track', 'artists' => []]];
    }
}
