<?php

namespace Tests\Unit\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\Providers\SpotifyPlaylistReader;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotifyPlaylistReaderTest extends TestCase
{
    #[DataProvider('acceptedItemCounts')]
    public function test_it_reads_zero_through_twenty_items_using_fixed_bounded_requests(int $count): void
    {
        Http::fakeSequence()->push($this->metadata($count))->push($this->items($count));

        $result = $this->reader()->read($this->reference(), $this->access());

        $this->assertInstanceOf(PlaylistSnapshot::class, $result);
        $this->assertCount($count, $result->items);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.spotify.com/v1/playlists/0123456789abcdefghijkl'
            && $request->hasHeader('Authorization', 'Bearer access-canary'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.spotify.com/v1/playlists/0123456789abcdefghijkl/items?limit=21&offset=0');
    }

    public static function acceptedItemCounts(): array
    {
        return [[0], [1], [20]];
    }

    public function test_it_preserves_duplicates_and_unavailable_placeholders(): void
    {
        $payload = $this->items(3);
        $payload['items'][1]['item']['id'] = 'track-0';
        $payload['items'][1]['item']['uri'] = 'spotify:track:track-0';
        $payload['items'][2]['item'] = null;
        Http::fakeSequence()->push($this->metadata(3))->push($payload);

        $result = $this->reader()->read($this->reference(), $this->access());

        $this->assertInstanceOf(PlaylistSnapshot::class, $result);
        $this->assertSame(['track-0', 'track-0', null], array_column($result->items, 'catalogId'));
        $this->assertFalse($result->items[2]->isAvailable);
    }

    public function test_it_refuses_a_twenty_first_item(): void
    {
        Http::fakeSequence()->push($this->metadata(21))->push($this->items(21));

        $this->assertSame(
            ImportFailureCode::TooManyItems,
            $this->reader()->read($this->reference(), $this->access()),
        );
    }

    #[DataProvider('unsupportedItems')]
    public function test_it_refuses_episodes_local_and_malformed_items(array $changes, ImportFailureCode $expected): void
    {
        $payload = $this->items(1);

        foreach ($changes as $path => $value) {
            data_set($payload, $path, $value);
        }

        Http::fakeSequence()->push($this->metadata(1))->push($payload);

        $this->assertSame($expected, $this->reader()->read($this->reference(), $this->access()));
    }

    public static function unsupportedItems(): array
    {
        return [
            'episode' => [['items.0.item.type' => 'episode'], ImportFailureCode::UnsupportedItem],
            'local wrapper' => [['items.0.is_local' => true], ImportFailureCode::UnsupportedItem],
            'local track' => [['items.0.item.is_local' => true], ImportFailureCode::UnsupportedItem],
            'malformed' => [['items.0.item.artists' => 'invalid'], ImportFailureCode::InvalidResponse],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_it_maps_provider_failures(int $status, array $body, ImportFailureCode $expected): void
    {
        Http::fake(['*' => Http::response($body, $status)]);

        $this->assertSame($expected, $this->reader()->read($this->reference(), $this->access()));
    }

    public static function providerFailures(): array
    {
        return [
            'wrong account' => [403, [], ImportFailureCode::PlaylistUnavailable],
            'missing' => [404, [], ImportFailureCode::PlaylistNotFound],
            'rate' => [429, [], ImportFailureCode::RateLimited],
            'quota' => [403, ['error' => ['reason' => 'QUOTA_EXCEEDED']], ImportFailureCode::QuotaLimited],
            'server' => [503, [], ImportFailureCode::ProviderUnavailable],
        ];
    }

    public function test_it_requires_ephemeral_spotify_access_and_never_falls_back(): void
    {
        Http::fake();

        $this->assertSame(ImportFailureCode::LinkedAccountRequired, $this->reader()->read($this->reference()));
        $this->assertSame(
            ImportFailureCode::ReauthorizationRequired,
            $this->reader()->read(
                $this->reference(),
                new StreamingAccessContext(StreamingProvider::YouTube, 'account-canary', 'token-canary'),
            ),
        );
        Http::assertNothingSent();
    }

    private function reader(): SpotifyPlaylistReader
    {
        return new SpotifyPlaylistReader;
    }

    private function reference(): PlaylistReference
    {
        return new PlaylistReference(
            StreamingProvider::Spotify,
            '0123456789abcdefghijkl',
            'https://open.spotify.com/playlist/0123456789abcdefghijkl',
        );
    }

    private function access(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::Spotify, 'account-canary', 'access-canary');
    }

    private function metadata(int $count): array
    {
        return [
            'id' => '0123456789abcdefghijkl',
            'name' => 'Canary playlist',
            'description' => 'Canary description',
            'snapshot_id' => 'revision-canary',
            'tracks' => ['total' => $count],
        ];
    }

    private function items(int $count): array
    {
        $items = [];

        for ($position = 0; $position < $count; $position++) {
            $items[] = [
                'is_local' => false,
                'item' => [
                    'type' => 'track',
                    'is_local' => false,
                    'id' => "track-{$position}",
                    'uri' => "spotify:track:track-{$position}",
                    'name' => "Track {$position}",
                    'artists' => [['name' => 'Canary creator']],
                    'album' => ['name' => 'Canary album'],
                    'duration_ms' => 1000,
                    'external_ids' => ['isrc' => 'CANARY000001'],
                    'is_playable' => true,
                ],
            ];
        }

        return [
            'items' => $items,
            'total' => $count,
            'next' => null,
        ];
    }
}
