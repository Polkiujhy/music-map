<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\PlaylistSync\FingerprintSourcePlaylist;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistReader;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlaylistSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{list<string>}> */
    public static function boundedSpotifyContents(): array
    {
        return [
            'empty' => [[]],
            'twenty ordered positions with a duplicate' => [[
                'track-00', 'track-01', 'track-02', 'track-03', 'track-04',
                'track-05', 'track-06', 'track-07', 'track-08', 'track-09',
                'track-10', 'track-11', 'track-12', 'track-13', 'track-14',
                'track-15', 'track-16', 'track-17', 'track-18', 'track-00',
            ]],
        ];
    }

    /** @param list<string> $identifiers */
    #[DataProvider('boundedSpotifyContents')]
    public function test_sqlite_reader_preserves_zero_twenty_order_duplicates_and_unavailable_items(array $identifiers): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->spotifyMetadata(count($identifiers), 'revision-matrix'))
            ->push($this->spotifyItems($identifiers, unavailablePosition: $identifiers === [] ? null : 1));

        $snapshot = (new SpotifySourcePlaylistReader)->read('playlist-canary', $this->spotifyAccess());

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $snapshot);
        $this->assertSame($identifiers, $snapshot->itemIdentifiers);
        $this->assertSame($identifiers, $snapshot->normalizedItemIdentifiers());
        $this->assertCount(count($identifiers), $snapshot->items);
        if ($identifiers !== []) {
            $this->assertFalse($snapshot->items[1]['is_available']);
            $this->assertSame('track-00', $snapshot->items[19]['catalog_id']);
        }
        Http::assertSentCount(2);
    }

    public function test_sqlite_reader_refuses_more_than_twenty_without_fetching_items(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->spotifyMetadata(21, 'revision-too-large'));

        $result = (new SpotifySourcePlaylistReader)->read('playlist-canary', $this->spotifyAccess());

        $this->assertSame(SourceSyncFailure::OverLimit, $result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/items'));
    }

    public function test_bank_change_completed_during_source_read_is_pushed_from_the_revalidated_bank_state(): void
    {
        [$playlist, $sync, $run] = $this->spotifyScenario('track-source');
        $this->app->instance(WithStreamingAccess::class, new MatrixSyncAccess);
        $this->app->instance(SourcePlaylistReaderRegistry::class, new SourcePlaylistReaderRegistry([
            new MutatingBankSourceReader(
                new SourcePlaylistSnapshot(['track-source'], 'revision-source', 'owner-canary'),
                function () use ($playlist): void {
                    $playlist->items()->delete();
                    PlaylistItem::factory()->for($playlist)->create([
                        'position' => 0,
                        'catalog_id' => 'track-bank-after-read',
                        'catalog_uri' => 'spotify:track:track-bank-after-read',
                    ]);
                    $playlist->update(['bank_content_edited_at' => now()]);
                },
            ),
        ]));
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['snapshot_id' => 'revision-pushed'])
            ->push($this->spotifyMetadata(1, 'revision-pushed'))
            ->push($this->spotifyItems(['track-bank-after-read']));

        $result = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertNull($result);
        $this->assertSame('completed', $run->refresh()->state);
        $this->assertSame('pushed', $sync->refresh()->last_outcome->value);
        $this->assertSame(['track-bank-after-read'], $playlist->refresh()->items()->pluck('catalog_id')->all());
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['uris'] === ['spotify:track:track-bank-after-read']);
    }

    /** @return array{Playlist, PlaylistSynchronization, PlaylistSyncRun} */
    private function spotifyScenario(string $identifier): array
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create([
            'provider_account_id' => 'owner-canary',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'playlist-canary',
            'streaming_account_id' => $account->id,
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'catalog_id' => $identifier,
            'catalog_uri' => "spotify:track:{$identifier}",
        ]);
        $playlist->load('items');
        $source = new SourcePlaylistSnapshot([$identifier]);
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => (new FingerprintPlaylistContent)->handle($playlist),
            'baseline_source_fingerprint' => (new FingerprintSourcePlaylist)->handle($source),
            'baseline_provider_revision' => 'revision-source',
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'state' => 'pending',
        ]);

        return [$playlist, $sync, $run];
    }

    private function spotifyAccess(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::Spotify, 'owner-canary', 'access-canary');
    }

    private function spotifyMetadata(int $count, string $revision): array
    {
        return [
            'id' => 'playlist-canary',
            'owner' => ['id' => 'owner-canary'],
            'snapshot_id' => $revision,
            'tracks' => ['total' => $count],
        ];
    }

    /** @param list<string> $identifiers */
    private function spotifyItems(array $identifiers, ?int $unavailablePosition = null): array
    {
        return [
            'items' => array_map(static fn (string $identifier, int $position): array => [
                'item' => [
                    'type' => 'track',
                    'id' => $identifier,
                    'uri' => "spotify:track:{$identifier}",
                    'name' => "Track {$position}",
                    'artists' => [['name' => 'Artist']],
                    'is_playable' => $position !== $unavailablePosition,
                ],
            ], $identifiers, array_keys($identifiers)),
            'next' => null,
        ];
    }
}

final class MatrixSyncAccess implements WithStreamingAccess
{
    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext(StreamingProvider::Spotify, 'owner-canary', 'access-canary'));

        return StreamingAccessResult::success();
    }
}

final class MutatingBankSourceReader implements SourcePlaylistReader
{
    public function __construct(
        private readonly SourcePlaylistSnapshot $snapshot,
        private readonly Closure $afterRead,
    ) {}

    public function provider(): StreamingProvider
    {
        return StreamingProvider::Spotify;
    }

    public function read(string $providerPlaylistId, StreamingAccessContext $access): SourcePlaylistSnapshot|SourceSyncFailure
    {
        ($this->afterRead)();

        return $this->snapshot;
    }
}
