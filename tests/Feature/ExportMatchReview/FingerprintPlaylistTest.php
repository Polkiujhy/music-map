<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintPlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprint_is_stable_across_row_ids_timestamps_and_provider_revision(): void
    {
        $first = $this->playlist([['position' => 0], ['position' => 1]]);
        $second = $this->playlist([['position' => 0], ['position' => 1]]);
        $second->update(['provider_revision' => 'different-revision']);

        $action = new FingerprintPlaylistContent;
        $this->assertSame($action->handle($first), $action->handle($second));
    }

    public function test_fingerprint_changes_for_order_duplicate_removal_or_export_data(): void
    {
        $action = new FingerprintPlaylistContent;
        $base = $this->playlist([['position' => 0, 'catalog_id' => 'a'], ['position' => 1, 'catalog_id' => 'b']]);
        $fingerprint = $action->handle($base);

        $this->assertNotSame($fingerprint, $action->handle($this->playlist([['position' => 0, 'catalog_id' => 'b'], ['position' => 1, 'catalog_id' => 'a']])));
        $this->assertNotSame($fingerprint, $action->handle($this->playlist([['position' => 0, 'catalog_id' => 'a'], ['position' => 1, 'catalog_id' => 'a']])));
        $this->assertNotSame($fingerprint, $action->handle($this->playlist([['position' => 0, 'catalog_id' => 'a']])));
        $this->assertNotSame($fingerprint, $action->handle($this->playlist([['position' => 0, 'catalog_id' => 'a', 'title' => 'Changed'], ['position' => 1, 'catalog_id' => 'b']])));
    }

    private function playlist(array $items): Playlist
    {
        $playlist = Playlist::factory()->create();
        foreach ($items as $attributes) {
            PlaylistItem::factory()->for($playlist)->create($attributes);
        }

        return $playlist->load('items');
    }
}
