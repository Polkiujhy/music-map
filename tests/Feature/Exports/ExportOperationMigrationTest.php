<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportOperationStatus;
use App\Enums\PlaylistRole;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportOperationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_new_playlists_are_sources_and_operation_keys_are_unique(): void
    {
        $playlist = Playlist::factory()->create();
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create();
        $operation = ExportOperation::factory()->for($review)->create([
            'user_id' => $playlist->user_id,
            'source_playlist_id' => $playlist->id,
            'active_key' => hash('sha256', 'active-operation'),
        ]);

        $this->assertSame(PlaylistRole::Source, $playlist->role);
        $this->assertSame(ExportOperationStatus::Queued, $operation->status);
        $this->assertSame($review->id, $operation->exportReview->id);

        $this->expectException(QueryException::class);
        ExportOperation::factory()->create([
            'export_review_id' => ExportReview::factory(),
            'active_key' => $operation->active_key,
        ]);
    }
}
