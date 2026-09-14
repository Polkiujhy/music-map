<?php

namespace Tests\Unit\Playlists;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintPlaylistContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_content_has_a_stable_fingerprint_that_ignores_row_identity_and_timestamps(): void
    {
        $first = $this->playlistWithItems();
        $second = $this->playlistWithItems();
        $second->forceFill([
            'created_at' => now()->subYear(),
            'updated_at' => now()->subMonth(),
            'bank_content_edited_at' => now(),
        ])->save();

        $fingerprint = new FingerprintPlaylistContent;

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->items->modelKeys(), $second->items->modelKeys());
        $this->assertSame($fingerprint->handle($first), $fingerprint->handle($second->load('items')));
    }

    public function test_order_duplicates_placeholders_and_export_fields_affect_the_fingerprint(): void
    {
        $fingerprint = new FingerprintPlaylistContent;
        $playlist = $this->playlistWithItems();
        $original = $fingerprint->handle($playlist);

        $first = $playlist->items[0];
        $second = $playlist->items[1];
        $first->update(['position' => 10]);
        $second->update(['position' => 0]);
        $first->update(['position' => 1]);
        $reordered = $fingerprint->handle($playlist->refresh()->load('items'));
        $this->assertNotSame($original, $reordered);

        $second->update(['catalog_id' => $first->catalog_id]);
        $duplicate = $fingerprint->handle($playlist->refresh()->load('items'));
        $this->assertNotSame($reordered, $duplicate);

        $second->update([
            'catalog_id' => null,
            'catalog_uri' => null,
            'title' => null,
            'creators' => [],
            'album' => null,
            'duration_milliseconds' => null,
            'isrc' => null,
            'is_available' => false,
        ]);
        $placeholder = $fingerprint->handle($playlist->refresh()->load('items'));
        $this->assertNotSame($duplicate, $placeholder);

        $playlist->update(['description' => 'Zmieniony opis / UTF-8']);
        $this->assertNotSame($placeholder, $fingerprint->handle($playlist->refresh()->load('items')));
    }

    private function playlistWithItems(): Playlist
    {
        $playlist = Playlist::factory()->create([
            'name' => 'Żółta playlista',
            'description' => 'Opis / canary',
        ]);

        PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'occurrence_id' => 'occurrence-a',
            'catalog_id' => 'catalog-a',
            'catalog_uri' => 'provider:catalog-a',
            'title' => 'Utwór A',
            'creators' => ['Pierwszy', 'Drugi'],
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'position' => 1,
            'occurrence_id' => 'occurrence-b',
            'catalog_id' => 'catalog-b',
            'catalog_uri' => 'provider:catalog-b',
            'title' => 'Utwór B',
            'creators' => ['Twórca'],
        ]);

        return $playlist->load('items');
    }
}
