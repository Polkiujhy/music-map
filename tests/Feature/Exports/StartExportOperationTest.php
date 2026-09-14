<?php

namespace Tests\Feature\Exports;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\Exports\StartExportOperation;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class StartExportOperationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh');

        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);
    }

    public function test_confirmation_creates_one_frozen_operation_and_dispatches_only_after_commit(): void
    {
        Queue::fake();
        $review = $this->readyReview();

        DB::beginTransaction();
        $firstOperationId = app(ConfirmExportReview::class)->handle($review->user, $review, []);
        Queue::assertNothingPushed();
        DB::commit();

        Queue::assertPushed(ExecuteExportOperation::class, fn ($job): bool => $job->operationId === $firstOperationId);
        $secondOperationId = app(ConfirmExportReview::class)->handle($review->user, $review->fresh(), []);
        $operation = $review->exportOperation()->firstOrFail();

        $this->assertSame($firstOperationId, $secondOperationId);
        $this->assertSame('Frozen name', $operation->playlist_name);
        $this->assertSame('Frozen description', $operation->playlist_description);
        $this->assertDatabaseCount('export_operations', 1);
    }

    public function test_rollback_leaves_neither_operation_nor_job(): void
    {
        Queue::fake();
        $review = $this->readyReview();

        DB::beginTransaction();
        app(ConfirmExportReview::class)->handle($review->user, $review, []);
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('export_operations', 0);
        $this->assertSame(ExportReviewStatus::Ready, $review->fresh()->status);
    }

    public function test_active_operation_blocks_a_new_manifest_for_the_same_destination(): void
    {
        Queue::fake();
        $firstReview = $this->readyReview();
        app(ConfirmExportReview::class)->handle($firstReview->user, $firstReview, []);

        $secondReview = ExportReview::factory()->for($firstReview->user)->for($firstReview->playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'managed-spotify',
            'source_fingerprint' => $firstReview->source_fingerprint,
        ]);
        $firstItem = $firstReview->items()->firstOrFail();
        ExportReviewItem::factory()->for($secondReview)->create($firstItem->only([
            'position', 'source_occurrence_id', 'source_catalog_id', 'source_catalog_uri',
            'source_title', 'source_creators', 'source_album', 'source_duration_milliseconds',
            'source_isrc', 'source_is_available', 'match_status', 'target_catalog_id',
            'target_catalog_uri', 'target_title', 'target_creators',
        ]));

        $this->expectException(ValidationException::class);
        app(ConfirmExportReview::class)->handle($firstReview->user, $secondReview, []);
    }

    public function test_wrong_owner_cannot_start_an_operation(): void
    {
        Queue::fake();
        $review = $this->readyReview();
        $review->forceFill([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ])->save();

        $this->expectException(NotFoundHttpException::class);
        app(StartExportOperation::class)->handle(
            User::factory()->create(),
            $review,
            $this->manifest($review),
        );
    }

    public function test_non_confirmed_review_cannot_start_an_operation(): void
    {
        Queue::fake();
        $review = $this->readyReview();

        try {
            app(StartExportOperation::class)->handle($review->user, $review, $this->manifest($review));
            $this->fail('A non-confirmed review must not start an operation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review', $exception->errors());
        }

        $this->assertDatabaseCount('export_operations', 0);
        Queue::assertNothingPushed();
    }

    private function readyReview(): ExportReview
    {
        $playlist = Playlist::factory()->create(['name' => 'Frozen name', 'description' => 'Frozen description']);
        $item = PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'catalog_id' => 'source-track',
            'catalog_uri' => 'source:track',
        ]);
        $playlist->load(['user', 'items']);
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'managed-spotify',
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'source_occurrence_id' => $item->occurrence_id,
            'source_catalog_id' => $item->catalog_id,
            'source_catalog_uri' => $item->catalog_uri,
            'source_title' => $item->title,
            'source_creators' => $item->creators,
            'source_is_available' => true,
            'match_status' => ExportMatchStatus::Matched,
            'target_catalog_id' => 'target-track',
            'target_catalog_uri' => 'spotify:track:target-track',
        ]);

        return $review->load('user');
    }

    private function manifest(ExportReview $review): ConfirmedExportManifest
    {
        return new ConfirmedExportManifest(
            $review->id,
            $review->playlist_id,
            $review->target_provider,
            $review->destination_type,
            $review->target_account_id,
            $review->target_market,
            $review->source_fingerprint,
            $review->playlist->name,
            $review->playlist->description,
            [],
        );
    }
}
