<?php

namespace Tests\Feature\PlaylistEditing;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\RefreshYouTubePlaylistMetadata;
use App\Actions\Playlists\ReplaceImportedPlaylist;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistFingerprintLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.playlist_import.youtube.api_key', 'api-key-canary');
    }

    public function test_no_op_preserves_the_fingerprint_while_reorder_and_remove_change_it(): void
    {
        $playlist = $this->playlistWithItems();
        $fingerprint = $this->fingerprint($playlist);
        $action = app(UpdateBankPlaylistItems::class);

        $unchanged = $action->handle(
            $playlist->user,
            $playlist->id,
            $fingerprint,
            $playlist->items->modelKeys(),
        );

        $this->assertSame($fingerprint, $this->fingerprint($unchanged));

        $reordered = $action->handle(
            $playlist->user,
            $playlist->id,
            $fingerprint,
            [$playlist->items[1]->id, $playlist->items[0]->id, $playlist->items[2]->id],
        );
        $reorderedFingerprint = $this->fingerprint($reordered);

        $this->assertNotSame($fingerprint, $reorderedFingerprint);

        $removed = $action->handle(
            $playlist->user,
            $playlist->id,
            $reorderedFingerprint,
            [$playlist->items[1]->id, $playlist->items[2]->id],
        );

        $this->assertNotSame($reorderedFingerprint, $this->fingerprint($removed));
    }

    public function test_full_snapshot_reimport_changes_the_fingerprint(): void
    {
        $playlist = $this->playlistWithItems();
        $before = $this->fingerprint($playlist);

        $reimported = app(ReplaceImportedPlaylist::class)->handle(
            $playlist->user,
            $this->snapshot($playlist, [
                ['replacement-occurrence', 'replacement-video', 'Replacement title'],
            ]),
        );

        $this->assertNotSame($before, $this->fingerprint($reimported));
    }

    public function test_refresh_of_changed_provider_data_changes_the_fingerprint(): void
    {
        $playlist = $this->playlistWithItems();
        $playlist->update(['bank_content_edited_at' => now()->subHour()]);
        $before = $this->fingerprint($playlist->refresh()->load('items'));

        Http::fakeSequence()
            ->push($this->metadata($playlist, 3))
            ->push($this->providerItems([
                ['occurrence-0', 'catalog-0', 'Changed provider title'],
                ['occurrence-1', 'catalog-1', 'Title 1'],
                ['occurrence-2', 'catalog-2', 'Title 2'],
            ]));

        $refreshed = app(RefreshYouTubePlaylistMetadata::class)->handle($playlist->id);

        $this->assertInstanceOf(Playlist::class, $refreshed);
        $this->assertNotSame($before, $this->fingerprint($refreshed));
        Http::assertSentCount(2);
    }

    public function test_retention_purge_changes_the_fingerprint(): void
    {
        Queue::fake();
        $playlist = $this->playlistWithItems();
        $playlist->update([
            'provider_metadata_refreshed_at' => now()->subDays(30),
            'bank_content_edited_at' => now()->subHour(),
        ]);
        $before = $this->fingerprint($playlist->refresh()->load('items'));

        Artisan::call('playlists:refresh-youtube-metadata');

        $purged = $playlist->refresh()->load('items');
        $this->assertNotSame($before, $this->fingerprint($purged));
        $this->assertCount(0, $purged->items);
        Queue::assertNothingPushed();
    }

    private function playlistWithItems(): Playlist
    {
        $playlist = Playlist::factory()->create([
            'source_playlist_id' => 'PL-fingerprint-lifecycle',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-fingerprint-lifecycle',
        ]);

        foreach (range(0, 2) as $position) {
            PlaylistItem::factory()->for($playlist)->create([
                'position' => $position,
                'occurrence_id' => "occurrence-{$position}",
                'catalog_id' => "catalog-{$position}",
                'catalog_uri' => "https://www.youtube.com/watch?v=catalog-{$position}",
                'title' => "Title {$position}",
                'creators' => ['Creator'],
                'album' => null,
                'duration_milliseconds' => null,
                'isrc' => null,
                'is_available' => true,
            ]);
        }

        return $playlist->load('user', 'items');
    }

    /**
     * @param  list<array{string, string, string}>  $items
     */
    private function snapshot(Playlist $playlist, array $items): PlaylistSnapshot
    {
        return new PlaylistSnapshot(
            provider: StreamingProvider::YouTube,
            providerPlaylistId: $playlist->source_playlist_id,
            sourceAccountId: 'channel-canary',
            canonicalUrl: $playlist->canonical_source_url,
            providerRevision: 'replacement-revision',
            name: 'Replacement playlist',
            description: 'Replacement description',
            providerMetadataRefreshedAt: new DateTimeImmutable,
            items: array_map(
                static fn (array $item, int $position): PlaylistItemSnapshot => new PlaylistItemSnapshot(
                    occurrenceId: $item[0],
                    catalogId: $item[1],
                    catalogUri: "youtube:video:{$item[1]}",
                    title: $item[2],
                    creators: ['Creator'],
                    album: null,
                    durationMilliseconds: 1000,
                    isrc: null,
                    position: $position,
                    isAvailable: true,
                ),
                $items,
                array_keys($items),
            ),
        );
    }

    private function metadata(Playlist $playlist, int $count): array
    {
        return ['items' => [[
            'id' => $playlist->source_playlist_id,
            'etag' => 'changed-provider-revision',
            'snippet' => [
                'title' => $playlist->name,
                'description' => $playlist->description,
                'channelId' => 'channel-canary',
            ],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    /**
     * @param  list<array{string, string, string}>  $items
     */
    private function providerItems(array $items): array
    {
        return [
            'items' => array_map(static fn (array $item, int $position): array => [
                'id' => $item[0],
                'snippet' => [
                    'position' => $position,
                    'title' => $item[2],
                    'videoOwnerChannelTitle' => 'Creator',
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $item[1]],
                ],
            ], $items, array_keys($items)),
            'pageInfo' => ['totalResults' => count($items)],
        ];
    }

    private function fingerprint(Playlist $playlist): string
    {
        return app(FingerprintPlaylistContent::class)->handle($playlist);
    }
}
