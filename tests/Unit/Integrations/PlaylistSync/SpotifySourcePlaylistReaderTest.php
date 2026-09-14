<?php

namespace Tests\Unit\Integrations\PlaylistSync;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotifySourcePlaylistReaderTest extends TestCase
{
    public function test_it_reads_owner_revision_duplicates_and_paginates_with_ephemeral_access(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->metadata(2))
            ->push(['items' => [$this->item('track-a')], 'next' => 'next-page'])
            ->push(['items' => [$this->item('track-a')], 'next' => null]);

        $snapshot = $this->reader()->read('playlist-canary', $this->access());

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $snapshot);
        $this->assertSame('owner-canary', $snapshot->ownerAccountId);
        $this->assertSame('revision-canary', $snapshot->providerRevision);
        $this->assertSame(['track-a', 'track-a'], $snapshot->itemIdentifiers);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'offset=1')
            && $request->hasHeader('Authorization', 'Bearer access-canary'));
    }

    public function test_it_returns_over_limit_before_reading_items(): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->metadataUrl() => Http::response($this->metadata(21))]);

        $this->assertSame(SourceSyncFailure::OverLimit, $this->reader()->read('playlist-canary', $this->access()));
        Http::assertSentCount(1);
    }

    public function test_it_preserves_null_and_unplayable_positions_as_unavailable_placeholders(): void
    {
        $unplayable = $this->item('track-b');
        $unplayable['item']['is_playable'] = false;
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->metadata(3))
            ->push(['items' => [$this->item('track-a'), ['item' => null], $unplayable], 'next' => null]);

        $snapshot = $this->reader()->read('playlist-canary', $this->access());

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $snapshot);
        $this->assertSame(['track-a', null, null], $snapshot->itemIdentifiers);
        $this->assertTrue($snapshot->items[0]['is_available']);
        $this->assertFalse($snapshot->items[1]['is_available']);
        $this->assertFalse($snapshot->items[2]['is_available']);
        $this->assertNull($snapshot->items[1]['catalog_id']);
        $this->assertNull($snapshot->items[2]['catalog_uri']);
    }

    #[DataProvider('failures')]
    public function test_it_maps_provider_failures(int $status, SourceSyncFailure $failure): void
    {
        Http::preventStrayRequests();
        Http::fake([$this->metadataUrl() => Http::response([], $status)]);

        $this->assertSame($failure, $this->reader()->read('playlist-canary', $this->access()));
    }

    public static function failures(): array
    {
        return [
            [401, SourceSyncFailure::Unauthorized],
            [403, SourceSyncFailure::Forbidden],
            [404, SourceSyncFailure::NotFound],
            [429, SourceSyncFailure::RateLimited],
            [503, SourceSyncFailure::ProviderUnavailable],
        ];
    }

    private function reader(): SpotifySourcePlaylistReader
    {
        return new SpotifySourcePlaylistReader;
    }

    private function access(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::Spotify, 'owner-canary', 'access-canary');
    }

    private function metadataUrl(): string
    {
        return 'https://api.spotify.com/v1/playlists/playlist-canary';
    }

    private function metadata(int $total): array
    {
        return ['id' => 'playlist-canary', 'owner' => ['id' => 'owner-canary'], 'snapshot_id' => 'revision-canary', 'tracks' => ['total' => $total]];
    }

    private function item(string $id): array
    {
        return ['item' => ['type' => 'track', 'id' => $id, 'uri' => "spotify:track:{$id}", 'name' => 'Track', 'artists' => [['name' => 'Artist']]]];
    }
}
