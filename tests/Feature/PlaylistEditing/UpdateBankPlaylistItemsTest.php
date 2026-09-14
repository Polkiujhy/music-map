<?php

namespace Tests\Feature\PlaylistEditing;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\PlaylistEditConflict;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UpdateBankPlaylistItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_atomically_reorders_and_removes_occurrences_without_editing_provider_data(): void
    {
        $playlist = $this->playlistWithItems(3);
        $before = $playlist->items->keyBy('id')->map->only([
            'occurrence_id',
            'catalog_id',
            'catalog_uri',
            'title',
            'creators',
            'album',
            'duration_milliseconds',
            'isrc',
            'is_available',
        ]);
        $orderedIds = [$playlist->items[2]->id, $playlist->items[0]->id];

        $updated = $this->action()->handle(
            $playlist->user,
            $playlist->id,
            $this->fingerprint($playlist),
            $orderedIds,
        );

        $this->assertSame($orderedIds, $updated->items->modelKeys());
        $this->assertSame([0, 1], $updated->items->pluck('position')->all());
        $this->assertInstanceOf(DateTimeImmutable::class, $updated->bank_content_edited_at);
        $this->assertSame($before[$orderedIds[0]], $updated->items[0]->only(array_keys($before[$orderedIds[0]])));
        $this->assertSame($before[$orderedIds[1]], $updated->items[1]->only(array_keys($before[$orderedIds[1]])));
        $this->assertDatabaseMissing('playlist_items', ['id' => $playlist->items[1]->id]);
    }

    public function test_it_accepts_and_reorders_exactly_twenty_items(): void
    {
        $playlist = $this->playlistWithItems(20);
        $orderedIds = array_reverse($playlist->items->modelKeys());

        $updated = $this->action()->handle(
            $playlist->user,
            $playlist->id,
            $this->fingerprint($playlist),
            $orderedIds,
        );

        $this->assertSame($orderedIds, $updated->items->modelKeys());
        $this->assertSame(range(0, 19), $updated->items->pluck('position')->all());
    }

    public function test_no_op_preserves_the_local_edit_marker(): void
    {
        $playlist = $this->playlistWithItems(2);
        $marker = now()->subDay()->toImmutable();
        $playlist->update(['bank_content_edited_at' => $marker]);
        $playlist->refresh()->load('items');
        $marker = $playlist->bank_content_edited_at;

        $updated = $this->action()->handle(
            $playlist->user,
            $playlist->id,
            $this->fingerprint($playlist),
            $playlist->items->modelKeys(),
        );

        $this->assertTrue($updated->bank_content_edited_at->equalTo($marker));
    }

    public function test_empty_result_requires_confirmation_and_then_removes_every_item(): void
    {
        $playlist = $this->playlistWithItems(2);
        $fingerprint = $this->fingerprint($playlist);

        $this->expectConflict(
            PlaylistEditConflict::EMPTY_CONFIRMATION_REQUIRED,
            fn () => $this->action()->handle($playlist->user, $playlist->id, $fingerprint, []),
        );
        $this->assertDatabaseCount('playlist_items', 2);

        $updated = $this->action()->handle($playlist->user, $playlist->id, $fingerprint, [], true);

        $this->assertCount(0, $updated->items);
        $this->assertNotNull($updated->bank_content_edited_at);
    }

    public function test_foreign_stale_duplicate_unknown_and_oversized_selections_do_not_mutate_the_playlist(): void
    {
        $playlist = $this->playlistWithItems(2);
        $snapshot = $playlist->fresh()->load('items')->toArray();
        $fingerprint = $this->fingerprint($playlist);

        $cases = [
            [PlaylistEditConflict::PLAYLIST_NOT_FOUND, User::factory()->create(), $fingerprint, $playlist->items->modelKeys()],
            [PlaylistEditConflict::CONTENT_CHANGED, $playlist->user, str_repeat('0', 64), $playlist->items->modelKeys()],
            [PlaylistEditConflict::INVALID_ITEM_SELECTION, $playlist->user, $fingerprint, [$playlist->items[0]->id, $playlist->items[0]->id]],
            [PlaylistEditConflict::INVALID_ITEM_SELECTION, $playlist->user, $fingerprint, [$playlist->items[0]->id, 999999]],
            [PlaylistEditConflict::TOO_MANY_ITEMS, $playlist->user, $fingerprint, range(1, 21)],
        ];

        foreach ($cases as [$reason, $owner, $expectedFingerprint, $ids]) {
            $this->expectConflict(
                $reason,
                fn () => $this->action()->handle($owner, $playlist->id, $expectedFingerprint, $ids, true),
            );
            $this->assertSame($snapshot, $playlist->fresh()->load('items')->toArray());
        }
    }

    public function test_an_error_during_reindexing_rolls_back_the_entire_edit(): void
    {
        $playlist = $this->playlistWithItems(2);
        $before = $playlist->fresh()->load('items')->toArray();
        $orderedIds = $playlist->items->modelKeys();
        $orderedIds = array_reverse($orderedIds);

        PlaylistItem::updating(function (PlaylistItem $item): void {
            if ($item->position === 0) {
                throw new RuntimeException('Deliberate reorder failure.');
            }
        });

        try {
            $this->action()->handle(
                $playlist->user,
                $playlist->id,
                $this->fingerprint($playlist),
                $orderedIds,
            );
            $this->fail('The reorder should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Deliberate reorder failure.', $exception->getMessage());
        } finally {
            PlaylistItem::flushEventListeners();
        }

        $this->assertSame($before, $playlist->fresh()->load('items')->toArray());
    }

    private function playlistWithItems(int $count): Playlist
    {
        $playlist = Playlist::factory()->create();

        foreach (range(0, $count - 1) as $position) {
            PlaylistItem::factory()->for($playlist)->create([
                'position' => $position,
                'occurrence_id' => "occurrence-{$position}",
                'catalog_id' => $position < 2 ? 'duplicate-catalog' : "catalog-{$position}",
                'catalog_uri' => "provider:catalog-{$position}",
                'title' => "Title {$position}",
                'creators' => ["Creator {$position}"],
                'album' => "Album {$position}",
                'duration_milliseconds' => 1000 + $position,
                'isrc' => "CANARY00000{$position}",
                'is_available' => true,
            ]);
        }

        return $playlist->load('user', 'items');
    }

    private function action(): UpdateBankPlaylistItems
    {
        return new UpdateBankPlaylistItems(new FingerprintPlaylistContent);
    }

    private function fingerprint(Playlist $playlist): string
    {
        return (new FingerprintPlaylistContent)->handle($playlist);
    }

    private function expectConflict(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail('A playlist edit conflict was expected.');
        } catch (PlaylistEditConflict $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }
}
