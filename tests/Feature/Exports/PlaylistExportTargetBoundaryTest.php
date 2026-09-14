<?php

namespace Tests\Feature\Exports;

use App\Actions\ExportReviews\ResolvedExportDestination;
use App\Actions\ExportReviews\StartExportReview;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\ImportPlaylist;
use App\Actions\Playlists\PlaylistEditConflict;
use App\Actions\Playlists\ReconcileEditedYouTubePlaylist;
use App\Actions\Playlists\RefreshYouTubePlaylistMetadata as RefreshMetadata;
use App\Actions\Playlists\ReplaceImportedPlaylist;
use App\Actions\Playlists\UpdateBankPlaylistItems;
use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Providers\SpotifyCatalogSearch;
use App\Integrations\ExportMatching\Providers\YouTubeCatalogSearch;
use App\Integrations\ExportMatching\YouTubeSearchBudget;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Jobs\PrepareExportReview;
use App\Jobs\RefreshYouTubePlaylistMetadata;
use App\Models\ExportReview;
use App\Models\Playlist;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
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

    public function test_target_is_rejected_by_replace_import_and_reimport_boundaries(): void
    {
        $target = $this->target();
        $snapshot = $this->snapshot($target);

        $this->assertSame(
            ImportFailureCode::ExportTargetConflict,
            app(ReplaceImportedPlaylist::class)->handle($target->user, $snapshot),
        );
        $this->assertSame(
            ImportFailureCode::ExportTargetConflict,
            app(ImportPlaylist::class)->handle($target->user, $target->canonical_source_url)->failureCode,
        );
        $this->actingAs($target->user)
            ->post(route('bank.playlists.reimport', $target), ['confirm_reimport' => '1'])
            ->assertNotFound();
        $this->assertSame(PlaylistRole::ExportTarget, $target->fresh()->role);
    }

    public function test_target_is_rejected_by_metadata_refresh_and_reconcile_actions(): void
    {
        $target = $this->target();

        $this->assertNull(app(RefreshMetadata::class)->handle($target->id));
        $this->assertNull(app(ReconcileEditedYouTubePlaylist::class)->handle(
            $target->id,
            $this->snapshot($target),
        ));
        $this->assertSame('Export target', $target->fresh()->name);
    }

    public function test_target_is_rejected_by_prepare_and_refresh_jobs(): void
    {
        $target = $this->target();
        $review = ExportReview::factory()->for($target->user)->for($target)->create([
            'status' => ExportReviewStatus::Queued,
        ]);

        (new PrepareExportReview($review->id))->handle(
            app(SpotifyCatalogSearch::class),
            app(YouTubeCatalogSearch::class),
            app(YouTubeSearchBudget::class),
            app(FingerprintPlaylistContent::class),
        );
        (new RefreshYouTubePlaylistMetadata($target->id))->handle(app(RefreshMetadata::class));

        $this->assertSame(ExportReviewStatus::Queued, $review->fresh()->status);
        $this->assertSame('Export target', $target->fresh()->name);
    }

    public function test_maintenance_command_ignores_export_targets(): void
    {
        Queue::fake();
        $expired = $this->target(['provider_metadata_refreshed_at' => now()->subDays(31)]);
        $aging = $this->target([
            'source_playlist_id' => 'PL-aging-export-target',
            'provider_metadata_refreshed_at' => now()->subDays(29),
        ]);

        Artisan::call('playlists:refresh-youtube-metadata');

        $this->assertSame('Export target', $expired->fresh()->name);
        Queue::assertNotPushed(
            RefreshYouTubePlaylistMetadata::class,
            fn (RefreshYouTubePlaylistMetadata $job): bool => in_array($job->playlistId, [$expired->id, $aging->id], true),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function target(array $overrides = []): Playlist
    {
        return Playlist::factory()->create(array_merge([
            'role' => PlaylistRole::ExportTarget,
            'source_provider' => StreamingProvider::YouTube,
            'source_playlist_id' => 'PL-export-target',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-export-target',
            'name' => 'Export target',
        ], $overrides))->load('user');
    }

    private function snapshot(Playlist $target): PlaylistSnapshot
    {
        return new PlaylistSnapshot(
            StreamingProvider::YouTube,
            $target->source_playlist_id,
            $target->source_account_id,
            $target->canonical_source_url,
            'replacement-revision',
            'Replacement name',
            'Replacement description',
            new DateTimeImmutable,
            [],
        );
    }
}
