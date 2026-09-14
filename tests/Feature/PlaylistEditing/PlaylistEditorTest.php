<?php

namespace Tests\Feature\PlaylistEditing;

use App\Livewire\PlaylistEditor;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PlaylistEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_draft_preserves_duplicate_occurrences_and_describes_placeholders(): void
    {
        $playlist = $this->playlistWithItems([
            ['catalog_id' => 'duplicate', 'title' => 'Pierwsze wystąpienie'],
            ['catalog_id' => 'duplicate', 'title' => 'Drugie wystąpienie'],
            ['catalog_id' => null, 'title' => null, 'is_available' => false],
        ]);

        $this->actingAs($playlist->user);

        Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id])
            ->assertSet('orderedItemIds', $playlist->items->modelKeys())
            ->assertSee('Pierwsze wystąpienie')
            ->assertSee('Drugie wystąpienie')
            ->assertSee('Duplikat')
            ->assertSee('Placeholder — niedostępna')
            ->assertSee('aria-label="Przenieś pozycję 1 w górę"', false)
            ->assertSee('aria-label="Przenieś pozycję 3 w dół"', false);
    }

    public function test_the_client_cannot_replace_the_ordered_item_ids(): void
    {
        $playlist = $this->playlistWithItems([[], []]);
        $this->actingAs($playlist->user);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(PlaylistEditor::class, [
            'playlistId' => (string) $playlist->id,
        ])->set('orderedItemIds', ['bad']);
    }

    public function test_moves_respect_boundaries_and_only_change_the_draft(): void
    {
        $playlist = $this->playlistWithItems([[], [], []]);
        $originalIds = $playlist->items->modelKeys();
        $this->actingAs($playlist->user);

        $component = Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id])
            ->call('moveUp', 0)
            ->assertSet('orderedItemIds', $originalIds)
            ->call('moveDown', 2)
            ->assertSet('orderedItemIds', $originalIds)
            ->call('moveDown', 0)
            ->assertSet('orderedItemIds', [$originalIds[1], $originalIds[0], $originalIds[2]])
            ->call('moveUp', 2)
            ->assertSet('orderedItemIds', [$originalIds[1], $originalIds[2], $originalIds[0]]);

        $this->assertSame($originalIds, $playlist->fresh()->items->modelKeys());
        $component->assertSee('Zapisz zmiany');
    }

    public function test_removals_stay_in_the_draft_and_the_last_item_requires_confirmation(): void
    {
        $playlist = $this->playlistWithItems([[], []]);
        $ids = $playlist->items->modelKeys();
        $this->actingAs($playlist->user);

        $component = Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id])
            ->call('removeItem', 0)
            ->assertSet('orderedItemIds', [$ids[1]])
            ->call('removeItem', 0)
            ->assertSet('orderedItemIds', [$ids[1]])
            ->assertSet('emptyRemovalPending', true)
            ->assertSee('Usunąć ostatnią pozycję?')
            ->call('cancelEmptyRemoval')
            ->assertSet('orderedItemIds', [$ids[1]])
            ->assertSet('emptyRemovalPending', false)
            ->call('removeItem', 0)
            ->call('confirmEmptyRemoval')
            ->assertSet('orderedItemIds', [])
            ->assertSet('emptyResultConfirmed', true);

        $this->assertDatabaseCount('playlist_items', 2);

        $component
            ->call('save')
            ->assertSee('Zmiany w playliście zostały zapisane.');

        $this->assertDatabaseCount('playlist_items', 0);
    }

    public function test_one_save_applies_the_entire_draft_and_refreshes_the_fingerprint(): void
    {
        $playlist = $this->playlistWithItems([[], [], []]);
        $ids = $playlist->items->modelKeys();
        $this->actingAs($playlist->user);

        $component = Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id]);
        $fingerprintBefore = $component->get('initialFingerprint');

        $component
            ->call('moveDown', 0)
            ->call('removeItem', 2)
            ->call('save')
            ->assertSet('orderedItemIds', [$ids[1], $ids[0]])
            ->assertSet('conflictMessage', null)
            ->assertSee('Zmiany w playliście zostały zapisane.')
            ->assertSet('emptyRemovalPending', false);

        $this->assertNotSame($fingerprintBefore, $component->get('initialFingerprint'));
        $this->assertSame([$ids[1], $ids[0]], $playlist->fresh()->items->modelKeys());
        $this->assertNotNull($playlist->fresh()->bank_content_edited_at);
    }

    public function test_a_no_op_save_does_not_move_the_local_edit_marker(): void
    {
        $playlist = $this->playlistWithItems([[], []]);
        $marker = now()->subDay()->startOfSecond();
        $playlist->update(['bank_content_edited_at' => $marker]);
        $this->actingAs($playlist->user);

        Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id])
            ->call('save')
            ->assertSee('Zmiany w playliście zostały zapisane.');

        $this->assertTrue($playlist->fresh()->bank_content_edited_at->equalTo($marker));
    }

    public function test_a_conflict_shows_an_alert_and_does_not_partially_save_the_draft(): void
    {
        $playlist = $this->playlistWithItems([[], []]);
        $ids = $playlist->items->modelKeys();
        $this->actingAs($playlist->user);

        $component = Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id])
            ->call('moveDown', 0);

        $playlist->items()->whereKey($ids[0])->update(['title' => 'Zmiana z drugiej karty']);

        $component
            ->call('save')
            ->assertSet('orderedItemIds', [$ids[1], $ids[0]])
            ->assertSee('role="alert"', false)
            ->assertSee('Załaduj aktualną wersję');

        $this->assertSame($ids, $playlist->fresh()->items->modelKeys());
        $this->assertSame('Zmiana z drugiej karty', $playlist->fresh()->items[0]->title);
    }

    public function test_mount_and_save_repeat_the_owner_scoped_lookup(): void
    {
        $playlist = $this->playlistWithItems([[]]);
        $this->actingAs($playlist->user);

        $component = Livewire::test(PlaylistEditor::class, ['playlistId' => (string) $playlist->id]);
        $playlist->delete();

        $this->expectException(ModelNotFoundException::class);

        $component->call('save');
    }

    /**
     * @param  list<array<string, mixed>>  $overrides
     */
    private function playlistWithItems(array $overrides): Playlist
    {
        $playlist = Playlist::factory()->create();

        foreach ($overrides as $position => $itemOverrides) {
            PlaylistItem::factory()->for($playlist)->create(array_merge([
                'position' => $position,
                'occurrence_id' => "occurrence-{$position}",
                'catalog_id' => "catalog-{$position}",
                'catalog_uri' => "provider:catalog-{$position}",
                'title' => "Pozycja {$position}",
                'creators' => ["Twórca {$position}"],
                'album' => "Album {$position}",
                'is_available' => true,
            ], $itemOverrides));
        }

        return $playlist->load('user', 'items');
    }
}
