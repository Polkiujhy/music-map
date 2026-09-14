<?php

namespace Tests\Feature\PlaylistEditing;

use App\Actions\Playlists\RefreshYouTubePlaylistMetadata;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeEditedPlaylistRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.playlist_import.youtube.api_key', 'api-key-canary');
    }

    public function test_refresh_reconciles_retained_occurrences_without_restoring_deletions_or_source_order(): void
    {
        $marker = now()->subHour()->startOfSecond();
        $playlist = Playlist::factory()->create([
            'source_playlist_id' => 'PL-edited',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-edited',
            'provider_revision' => 'local-projection-revision',
            'bank_content_edited_at' => $marker,
        ]);
        $missing = PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'occurrence_id' => 'occurrence-missing',
            'catalog_id' => 'old-missing-video',
            'title' => 'Old missing title',
        ]);
        $retained = PlaylistItem::factory()->for($playlist)->create([
            'position' => 1,
            'occurrence_id' => 'occurrence-retained',
            'catalog_id' => 'old-retained-video',
            'title' => 'Old retained title',
        ]);
        $persistedMarker = $playlist->fresh()->bank_content_edited_at;

        Http::fakeSequence()
            ->push($this->metadata('Fresh playlist name', 2))
            ->push($this->items([
                ['occurrence-retained', 'fresh-retained-video', 'Fresh retained title'],
                ['occurrence-source-only', 'new-source-video', 'Source-only title'],
            ]));

        $refreshStartedAt = now()->startOfSecond();
        $result = app(RefreshYouTubePlaylistMetadata::class)->handle($playlist->id);
        $refreshFinishedAt = now()->endOfSecond();

        $this->assertInstanceOf(Playlist::class, $result);
        $playlist->refresh()->load('items');
        $this->assertSame([$missing->id, $retained->id], $playlist->items->modelKeys());
        $this->assertSame([0, 1], $playlist->items->pluck('position')->all());
        $this->assertSame('occurrence-missing', $playlist->items[0]->occurrence_id);
        $this->assertNull($playlist->items[0]->catalog_id);
        $this->assertNull($playlist->items[0]->title);
        $this->assertSame([], $playlist->items[0]->creators);
        $this->assertFalse($playlist->items[0]->is_available);
        $this->assertSame('fresh-retained-video', $playlist->items[1]->catalog_id);
        $this->assertSame('Fresh retained title', $playlist->items[1]->title);
        $this->assertDatabaseMissing('playlist_items', ['occurrence_id' => 'occurrence-source-only']);
        $this->assertSame('local-projection-revision', $playlist->provider_revision);
        $this->assertSame(
            $persistedMarker->toDateTimeString(),
            $playlist->bank_content_edited_at->toDateTimeString(),
        );
        $this->assertSame('Fresh playlist name', $playlist->name);
        $this->assertTrue(
            $playlist->provider_metadata_refreshed_at->betweenIncluded(
                $refreshStartedAt,
                $refreshFinishedAt,
            ),
        );
    }

    public function test_refresh_with_an_unreliable_local_occurrence_fails_without_partial_publication(): void
    {
        $playlist = Playlist::factory()->create([
            'source_playlist_id' => 'PL-edited',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-edited',
            'bank_content_edited_at' => now()->subHour(),
            'provider_metadata_refreshed_at' => now()->subDays(28),
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'occurrence_id' => null,
            'title' => 'Must remain unchanged',
        ]);
        $before = $playlist->fresh()->load('items')->toArray();

        Http::fakeSequence()
            ->push($this->metadata('Fresh playlist name', 1))
            ->push($this->items([
                ['occurrence-retained', 'fresh-video', 'Fresh title'],
            ]));

        $result = app(RefreshYouTubePlaylistMetadata::class)->handle($playlist->id);

        $this->assertSame(ImportFailureCode::InvalidResponse, $result);
        $this->assertSame($before, $playlist->fresh()->load('items')->toArray());
    }

    private function metadata(string $name, int $count): array
    {
        return ['items' => [[
            'id' => 'PL-edited',
            'etag' => 'source-revision-new',
            'snippet' => ['title' => $name, 'description' => 'Fresh description', 'channelId' => 'fresh-channel'],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    /**
     * @param  list<array{string, string, string}>  $values
     */
    private function items(array $values): array
    {
        return [
            'items' => array_map(static fn (array $value, int $position): array => [
                'id' => $value[0],
                'snippet' => [
                    'position' => $position,
                    'title' => $value[2],
                    'videoOwnerChannelTitle' => 'Fresh creator',
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $value[1]],
                ],
            ], $values, array_keys($values)),
            'pageInfo' => ['totalResults' => count($values)],
        ];
    }
}
