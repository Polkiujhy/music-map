<?php

namespace Tests\Feature\ManagedAccountExport;

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
use App\Enums\PlaylistOrigin;
use App\Enums\PlaylistSyncStatus;
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
use App\Models\PlaylistExport;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\StreamingAccount;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExportTargetBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_replace_import_preserves_linked_export_target_and_its_items(): void
    {
        $target = $this->linkedTarget();
        $before = app(FingerprintPlaylistContent::class)->handle($target);
        $link = $target->managedExportTarget()->firstOrFail();
        Http::fake();

        $this->assertSame(ImportFailureCode::ExportTargetConflict, app(ReplaceImportedPlaylist::class)->handle(
            $target->user,
            $this->snapshot($target),
        ));
        $this->assertSame(ImportFailureCode::ExportTargetConflict, app(ImportPlaylist::class)->handle(
            $target->user,
            $target->canonical_source_url,
        )->failureCode);

        $this->assertSame($before, app(FingerprintPlaylistContent::class)->handle($target->fresh()));
        $this->assertSame(PlaylistOrigin::ManagedTarget, $target->fresh()->origin);
        $this->assertSame($target->id, $link->fresh()->target_playlist_id);
        Http::assertNothingSent();
    }

    public function test_linked_export_target_is_rejected_by_edit_reimport_and_review_endpoints(): void
    {
        $target = $this->linkedTarget();
        Http::fake();
        Queue::fake();

        $this->actingAs($target->user)->get(route('bank.playlists.edit', $target))->assertNotFound();
        $this->actingAs($target->user)->post(route('bank.playlists.reimport', $target), [
            'confirm_reimport' => '1',
        ])->assertNotFound();
        $this->actingAs($target->user)->post(route('export-reviews.store', $target), [
            'target_provider' => StreamingProvider::Spotify->value,
            'destination_type' => ExportDestinationType::Managed->value,
        ])->assertNotFound();

        $this->assertSame(['preserved-track'], $target->items()->pluck('catalog_id')->all());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_direct_editor_and_review_actions_cannot_treat_linked_export_as_source(): void
    {
        $target = $this->linkedTarget();
        try {
            app(UpdateBankPlaylistItems::class)->handle($target->user, $target->id, 'irrelevant', []);
            $this->fail('Export targets must not be editable as bank sources.');
        } catch (PlaylistEditConflict $exception) {
            $this->assertSame(PlaylistEditConflict::PLAYLIST_NOT_FOUND, $exception->reason);
        }

        $this->expectException(ValidationException::class);
        app(StartExportReview::class)->handle($target->user, $target, new ResolvedExportDestination(
            StreamingProvider::Spotify,
            ExportDestinationType::Managed,
            null,
            'managed-spotify',
            'GB',
        ));
    }

    public function test_refresh_reconcile_and_old_jobs_preserve_export_snapshot(): void
    {
        $target = $this->linkedTarget();
        $before = app(FingerprintPlaylistContent::class)->handle($target);
        $review = ExportReview::factory()->for($target->user)->for($target)->create([
            'status' => ExportReviewStatus::Queued,
        ]);
        Http::fake();

        $this->assertNull(app(RefreshMetadata::class)->handle($target->id));
        $this->assertNull(app(ReconcileEditedYouTubePlaylist::class)->handle($target->id, $this->snapshot($target)));
        (new PrepareExportReview($review->id))->handle(
            app(SpotifyCatalogSearch::class),
            app(YouTubeCatalogSearch::class),
            app(YouTubeSearchBudget::class),
            app(FingerprintPlaylistContent::class),
        );
        (new RefreshYouTubePlaylistMetadata($target->id))->handle(app(RefreshMetadata::class));

        $this->assertSame(ExportReviewStatus::Queued, $review->fresh()->status);
        $this->assertSame($before, app(FingerprintPlaylistContent::class)->handle($target->fresh()));
        Http::assertNothingSent();
    }

    #[DataProvider('protectedSyncStates')]
    public function test_metadata_maintenance_excludes_both_export_targets_and_sync_owned_sources(PlaylistSyncStatus $status): void
    {
        Queue::fake();
        $protected = [];
        foreach ([29, 31] as $age) {
            $protected[] = $this->linkedTarget(['provider_metadata_refreshed_at' => now()->subDays($age)]);
            $source = Playlist::factory()->create([
                'source_provider' => StreamingProvider::YouTube,
                'provider_metadata_refreshed_at' => now()->subDays($age),
                'name' => 'Sync-owned snapshot',
            ]);
            PlaylistItem::factory()->for($source)->create(['catalog_id' => 'preserved-track']);
            PlaylistSynchronization::factory()->for($source)->create(['status' => $status]);
            $protected[] = $source;
        }
        $agingSource = Playlist::factory()->create([
            'source_provider' => StreamingProvider::YouTube,
            'provider_metadata_refreshed_at' => now()->subDays(29),
        ]);
        $expiredSource = Playlist::factory()->create([
            'source_provider' => StreamingProvider::YouTube,
            'provider_metadata_refreshed_at' => now()->subDays(31),
        ]);
        PlaylistItem::factory()->for($expiredSource)->create();

        $this->assertSame(0, Artisan::call('playlists:refresh-youtube-metadata'));

        foreach ($protected as $playlist) {
            $this->assertSame($playlist->name, $playlist->fresh()->name);
            $this->assertSame(['preserved-track'], $playlist->items()->pluck('catalog_id')->all());
            Queue::assertNotPushed(RefreshYouTubePlaylistMetadata::class,
                fn (RefreshYouTubePlaylistMetadata $job): bool => $job->playlistId === $playlist->id);
        }
        Queue::assertPushed(RefreshYouTubePlaylistMetadata::class,
            fn (RefreshYouTubePlaylistMetadata $job): bool => $job->playlistId === $agingSource->id);
        Queue::assertPushed(RefreshYouTubePlaylistMetadata::class, 1);
        $this->assertNull($expiredSource->fresh()->name);
        $this->assertSame(0, $expiredSource->items()->count());
    }

    public static function protectedSyncStates(): array
    {
        return [
            [PlaylistSyncStatus::PendingConfirmation],
            [PlaylistSyncStatus::Enabled],
            [PlaylistSyncStatus::Attention],
        ];
    }

    private function linkedTarget(array $overrides = []): Playlist
    {
        $source = Playlist::factory()->create(['source_provider' => StreamingProvider::Spotify]);
        $account = StreamingAccount::factory()->for($source->user)->create([
            'provider' => StreamingProvider::YouTube,
        ]);
        $target = Playlist::factory()->for($source->user)->create(array_merge([
            'origin' => PlaylistOrigin::ManagedTarget,
            'source_provider' => StreamingProvider::YouTube,
            'source_playlist_id' => 'PL-export-target-'.$source->id,
            'source_account_id' => $account->provider_account_id,
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-export-target-'.$source->id,
            'name' => 'Linked export target',
        ], $overrides));
        PlaylistItem::factory()->for($target)->create(['catalog_id' => 'preserved-track']);
        PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => StreamingProvider::YouTube,
            'destination_type' => ExportDestinationType::Linked,
            'streaming_account_id' => $account->id,
            'target_account_id' => $account->provider_account_id,
            'target_playlist_id' => $target->id,
        ]);

        return $target->load('user');
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
