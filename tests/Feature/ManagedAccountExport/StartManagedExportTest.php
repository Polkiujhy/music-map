<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Jobs\RunManagedExport;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistItem;
use App\Models\StreamingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StartManagedExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);
    }

    public function test_managed_confirmation_creates_one_operation_and_target_attempt_idempotently(): void
    {
        $review = $this->readyReview(ExportDestinationType::Managed);
        $action = app(ConfirmExportReview::class);

        $action->handle($review->user, $review, []);
        $action->handle($review->user, $review->fresh(), []);

        $this->assertSame(1, ExportOperation::query()->where('export_review_id', $review->id)->count());
        $this->assertSame(1, PlaylistExport::query()->count());
        $this->assertSame(1, PlaylistExport::query()->firstOrFail()->targetAttempts()->count());
        Queue::assertPushed(RunManagedExport::class, 1);
    }

    public function test_linked_confirmation_remains_a_handoff_without_managed_operation(): void
    {
        $review = $this->readyReview(ExportDestinationType::Linked);

        app(ConfirmExportReview::class)->handle($review->user, $review, []);

        $this->assertDatabaseCount('export_operations', 0);
        $this->assertDatabaseCount('playlist_exports', 0);
        Queue::assertNothingPushed();
    }

    private function readyReview(ExportDestinationType $destination): ExportReview
    {
        $playlist = Playlist::factory()->create();
        $provider = $destination === ExportDestinationType::Linked
            ? StreamingProvider::YouTube
            : StreamingProvider::Spotify;
        $account = $destination === ExportDestinationType::Linked
            ? StreamingAccount::factory()->youtube()->for($playlist->user)->create([
                'provider_account_id' => 'linked-youtube',
            ])
            : null;
        PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'catalog_id' => 'source-one',
            'catalog_uri' => 'source:one',
        ]);
        $playlist->load(['user', 'items']);
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'destination_type' => $destination,
            'target_provider' => $provider,
            'streaming_account_id' => $account?->getKey(),
            'target_account_id' => $account?->provider_account_id ?? 'managed-spotify',
            'target_market' => $provider === StreamingProvider::Spotify ? 'GB' : null,
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'source_catalog_id' => 'source-one',
            'source_catalog_uri' => 'source:one',
            'target_catalog_id' => 'target-one',
            'target_catalog_uri' => $provider === StreamingProvider::Spotify
                ? 'spotify:track:one'
                : 'https://www.youtube.com/watch?v=target-one',
        ]);

        return $review;
    }
}
