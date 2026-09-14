<?php

namespace Tests\Feature\Exports;

use App\Actions\ExportReviews\ResolvedExportDestination;
use App\Actions\ExportReviews\StartExportReview;
use App\Actions\Playlists\PlaylistEditConflict;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Enums\ExportDestinationType;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Models\Playlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PlaylistExportTargetBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_is_hidden_from_bank_and_rejected_by_direct_edit_and_review_actions(): void
    {
        $source = Playlist::factory()->create();
        $target = Playlist::factory()->for($source->user)->create([
            'role' => PlaylistRole::ExportTarget,
            'source_playlist_id' => 'export-target-id',
        ]);

        $this->actingAs($source->user)->get(route('bank.index'))
            ->assertOk()
            ->assertSee($source->name)
            ->assertDontSee('export-target-id');

        try {
            app(UpdateBankPlaylistItems::class)->handle($source->user, $target->id, 'irrelevant', []);
            $this->fail('An export target must not be editable.');
        } catch (PlaylistEditConflict $exception) {
            $this->assertSame(PlaylistEditConflict::PLAYLIST_NOT_FOUND, $exception->reason);
        }

        $this->expectException(NotFoundHttpException::class);
        app(StartExportReview::class)->handle(
            $source->user,
            $target,
            new ResolvedExportDestination(
                StreamingProvider::Spotify,
                ExportDestinationType::Managed,
                null,
                'managed-spotify',
                'GB',
            ),
        );
    }
}
