<?php

namespace Tests\Feature\Playlists;

use App\Actions\Playlists\ReplaceImportedPlaylist;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PlaylistPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_source_is_unique_per_user_and_items_keep_order_duplicates_and_placeholders(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $snapshot = $this->snapshot('Original', [
            $this->item(0, 'same-catalog'),
            $this->item(1, 'same-catalog'),
            $this->item(2, null, false),
        ]);
        $action = new ReplaceImportedPlaylist;

        $first = $action->handle($firstUser, $snapshot);
        $second = $action->handle($secondUser, $snapshot);

        $this->assertNotSame($first->id, $second->id);
        $this->assertCount(1, $firstUser->playlists);
        $this->assertSame([0, 1, 2], $first->items->pluck('position')->all());
        $this->assertSame(['same-catalog', 'same-catalog', null], $first->items->pluck('catalog_id')->all());
        $this->assertFalse($first->items->last()->is_available);
        $this->assertSame([], $first->items->last()->creators);
    }

    public function test_reimport_updates_the_same_playlist_and_exactly_replaces_items(): void
    {
        $user = User::factory()->create();
        $action = new ReplaceImportedPlaylist;
        $original = $action->handle($user, $this->snapshot('Original', [
            $this->item(0, 'old-a'),
            $this->item(1, 'old-b'),
        ]));

        $replacement = $action->handle($user, $this->snapshot('Replacement', [
            $this->item(0, 'new-a'),
        ]));

        $this->assertSame($original->id, $replacement->id);
        $this->assertSame('Replacement', $replacement->name);
        $this->assertSame(['new-a'], $replacement->items->pluck('catalog_id')->all());
        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseCount('playlist_items', 1);
    }

    public function test_child_failure_rolls_back_metadata_and_old_items(): void
    {
        $user = User::factory()->create();
        $action = new ReplaceImportedPlaylist;
        $playlist = $action->handle($user, $this->snapshot('Original', [$this->item(0, 'old')]));

        PlaylistItem::creating(function (PlaylistItem $item): void {
            if ($item->catalog_id === 'explode') {
                throw new RuntimeException('Deliberate child persistence failure.');
            }
        });

        try {
            $action->handle($user, $this->snapshot('Replacement', [$this->item(0, 'explode')]));
            $this->fail('The replacement should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate child persistence failure.', $exception->getMessage());
        } finally {
            PlaylistItem::flushEventListeners();
        }

        $playlist->refresh()->load('items');
        $this->assertSame('Original', $playlist->name);
        $this->assertSame(['old'], $playlist->items->pluck('catalog_id')->all());
    }

    public function test_models_expose_owned_ordered_relationships_and_expected_casts(): void
    {
        $playlist = Playlist::factory()->create();
        PlaylistItem::factory()->for($playlist)->create(['position' => 1]);
        PlaylistItem::factory()->for($playlist)->create(['position' => 0]);

        $this->assertTrue($playlist->user->playlists->contains($playlist));
        $this->assertSame([0, 1], $playlist->items->pluck('position')->all());
        $this->assertSame(StreamingProvider::YouTube, $playlist->source_provider);
        $this->assertInstanceOf(DateTimeImmutable::class, $playlist->imported_at);
        $this->assertIsArray($playlist->items->first()->creators);
        $this->assertIsBool($playlist->items->first()->is_available);
    }

    /**
     * @param  list<PlaylistItemSnapshot>  $items
     */
    private function snapshot(string $name, array $items): PlaylistSnapshot
    {
        return new PlaylistSnapshot(
            StreamingProvider::YouTube,
            'PL_canary',
            null,
            'https://www.youtube.com/playlist?list=PL_canary',
            'canary-revision',
            $name,
            'Canary description',
            new DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            $items,
        );
    }

    private function item(int $position, ?string $catalogId, bool $available = true): PlaylistItemSnapshot
    {
        return new PlaylistItemSnapshot(
            "occurrence-{$position}",
            $catalogId,
            $catalogId === null ? null : "canary:{$catalogId}",
            $available ? 'Canary title' : null,
            $available ? ['Canary creator'] : [],
            $available ? 'Canary album' : null,
            $available ? 1000 : null,
            $available ? 'CANARY000001' : null,
            $position,
            $available,
        );
    }
}
