<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Jobs\PrepareExportReview;
use App\Jobs\RunManagedExport;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManagedExportRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-owner',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);
    }

    public function test_retry_is_owner_scoped_post_csrf_throttled_and_preserves_operation_target_and_marker(): void
    {
        [$operation, $attempt] = $this->operation(ExportOperationStatus::PartialFailed);
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($other)->post(route('managed-exports.retry', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]))->assertNotFound();

        $route = Route::getRoutes()->getByName('managed-exports.retry');
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('verified', $route->gatherMiddleware());
        $this->assertContains('throttle:managed-export-action', $route->gatherMiddleware());

        $this->actingAs($operation->user)->post(route('managed-exports.retry', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]))->assertRedirect();

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Queued, $operation->status);
        $this->assertSame(1, $operation->retry_generation);
        $this->assertSame(0, $operation->automatic_claim_count);
        $this->assertSame($attempt->marker, $attempt->fresh()->marker);
        $this->assertSame($attempt->provider_playlist_id, $attempt->fresh()->provider_playlist_id);
        Queue::assertPushed(RunManagedExport::class, fn ($job): bool => $job->exportOperationId === $operation->id);
    }

    public function test_retry_respects_cooldown_and_historical_account_mismatch_fails_closed(): void
    {
        [$operation] = $this->operation(ExportOperationStatus::PartialFailed, [
            'retry_available_at' => now()->addMinute(),
        ]);

        $this->actingAs($operation->user)->post(route('managed-exports.retry', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]))->assertSessionHasErrors('operation');
        $this->assertSame(ExportOperationStatus::PartialFailed, $operation->fresh()->status);

        $operation->update(['retry_available_at' => now()->subSecond()]);
        config(['services.platform_access.spotify.technical.account_id' => 'replacement-owner']);
        $this->actingAs($operation->user)->post(route('managed-exports.retry', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]))->assertSessionHasErrors('operation');
        Queue::assertNothingPushed();
    }

    public function test_explicit_recreation_requires_confirmation_and_keeps_every_marker_and_locator_generation(): void
    {
        [$operation, $attempt] = $this->operation(ExportOperationStatus::RecreateRequired);

        $this->actingAs($operation->user)->post(route('managed-exports.recreate', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]))->assertSessionHasErrors('confirm_recreation');

        $this->actingAs($operation->user)->post(route('managed-exports.recreate', [
            $operation->playlistExport->source_playlist_id,
            $operation,
        ]), ['confirm_recreation' => '1'])->assertRedirect();

        $attempt->refresh();
        $export = $operation->playlistExport->fresh();
        $this->assertSame(PlaylistExportTargetAttempt::STATUS_ABANDONED, $attempt->status);
        $this->assertSame('old-target', $attempt->provider_playlist_id);
        $this->assertSame(2, $export->target_generation);
        $this->assertSame(2, $export->targetAttempts()->count());
        $next = $export->targetAttempts()->where('generation', 2)->firstOrFail();
        $this->assertNotSame($attempt->marker, $next->marker);
        $this->assertNull($next->provider_playlist_id);
        $this->assertSame($operation->id, $operation->fresh()->id);
    }

    public function test_active_or_partial_operation_blocks_a_direct_new_review_even_after_source_fingerprint_changes(): void
    {
        [$operation] = $this->operation(ExportOperationStatus::PartialFailed);
        $playlist = $operation->playlistExport->sourcePlaylist;
        PlaylistItem::factory()->for($playlist)->create(['position' => 0, 'title' => 'Changed']);
        $before = ExportReview::query()->count();

        $this->actingAs($operation->user)->post(route('export-reviews.store', $playlist), [
            'target_provider' => StreamingProvider::Spotify->value,
            'destination_type' => ExportDestinationType::Managed->value,
        ])->assertSessionHasErrors('review');

        $this->assertSame($before, ExportReview::query()->count());
        Queue::assertNotPushed(PrepareExportReview::class);
    }

    /** @return array{ExportOperation, PlaylistExportTargetAttempt} */
    private function operation(ExportOperationStatus $status, array $attributes = []): array
    {
        $source = Playlist::factory()->create();
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'destination_type' => ExportDestinationType::Managed,
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'managed-owner',
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'managed-owner',
        ]);
        $attempt = PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'provider_playlist_id' => 'old-target',
            'canonical_url' => 'https://open.spotify.com/playlist/old-target',
        ]);
        $operation = ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create(array_merge([
            'status' => $status,
            'completed_at' => now(),
            'retry_available_at' => now()->subSecond(),
            'automatic_claim_count' => 3,
        ], $attributes));

        return [$operation->load(['user', 'playlistExport.sourcePlaylist']), $attempt];
    }
}
