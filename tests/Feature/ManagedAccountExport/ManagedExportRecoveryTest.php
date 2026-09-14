<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ManagedAccountExport\PublishManagedExport;
use App\Actions\ManagedAccountExport\RunManagedExport as RunManagedExportAction;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Jobs\RunManagedExport;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ManagedExportRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_orphaned_committed_intent_is_recovered_without_new_domain_records(): void
    {
        $operation = $this->operation();
        $counts = $this->domainCounts();
        DB::table('export_operations')->where('id', $operation->id)->update([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $this->artisan('managed-exports:recover')->assertSuccessful();

        Queue::assertPushed(RunManagedExport::class, fn ($job): bool => $job->exportOperationId === $operation->id);
        $this->assertSame($counts, $this->domainCounts());
        $this->assertNotNull($operation->fresh()->job_published_at);
    }

    public function test_repeated_scheduler_does_not_republish_before_durable_lease_expires(): void
    {
        $operation = $this->operation();
        DB::table('export_operations')->where('id', $operation->id)->update(['updated_at' => now()->subMinute()]);

        $this->artisan('managed-exports:recover')->assertSuccessful();
        $this->artisan('managed-exports:recover')->assertSuccessful();
        Queue::assertPushed(RunManagedExport::class, 1);

        $this->travel(PublishManagedExport::PUBLICATION_LEASE_SECONDS + 1)->seconds();
        $this->artisan('managed-exports:recover')->assertSuccessful();
        Queue::assertPushed(RunManagedExport::class, 2);
    }

    public function test_enqueue_failure_leaves_a_leased_intent_that_can_be_recovered_later(): void
    {
        $operation = $this->operation();
        $originalDispatcher = app(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(PublishManagedExport::class)->publish($operation->id);
            $this->fail('A failed enqueue must be visible to the caller.');
        } catch (RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $operation->refresh();
        $this->assertNotNull($operation->job_publication_lease_until);
        $this->assertNull($operation->job_published_at);
        $this->assertSame(ExportOperationStatus::Queued, $operation->status);

        $this->app->instance(Dispatcher::class, $originalDispatcher);
        $this->travel(PublishManagedExport::PUBLICATION_LEASE_SECONDS + 1)->seconds();
        $this->assertTrue(app(PublishManagedExport::class)->publish($operation->id));
        Queue::assertPushed(RunManagedExport::class, 1);
    }

    public function test_stale_processing_waits_for_full_worker_lease_before_republication(): void
    {
        $this->freezeSecond();

        $operation = $this->operation(ExportOperationStatus::Processing, [
            'heartbeat_at' => now()->subSeconds(509),
        ]);

        $this->artisan('managed-exports:recover')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(1)->seconds();
        $this->artisan('managed-exports:recover')->assertSuccessful();
        Queue::assertPushed(RunManagedExport::class, 1);
    }

    public function test_recovery_and_duplicate_payloads_do_not_reset_durable_claim_budget_or_use_dispatch_uniqueness(): void
    {
        $operation = $this->operation(ExportOperationStatus::Queued, [
            'automatic_claim_count' => 2,
            'retry_generation' => 2,
        ]);
        DB::table('export_operations')->where('id', $operation->id)->update(['updated_at' => now()->subMinute()]);

        $this->artisan('managed-exports:recover')->assertSuccessful();
        $this->assertSame(2, $operation->fresh()->automatic_claim_count);
        $this->assertNull(app(RunManagedExportAction::class)->handle($operation->id, 1));
        $this->assertSame(2, $operation->fresh()->automatic_claim_count);
        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);

        $job = new RunManagedExport($operation->id);
        $this->assertNotInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame(510, $job->middleware()[0]->expiresAfter);
        $this->assertNull($job->middleware()[0]->releaseAfter);
    }

    private function operation(ExportOperationStatus $status = ExportOperationStatus::Queued, array $attributes = []): ExportOperation
    {
        $source = Playlist::factory()->create();
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create();

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create(array_merge([
            'status' => $status,
            'heartbeat_at' => $status === ExportOperationStatus::Processing ? now() : null,
        ], $attributes));
    }

    /** @return array{int, int, int, int} */
    private function domainCounts(): array
    {
        return [
            DB::table('export_operations')->count(),
            DB::table('playlist_exports')->count(),
            DB::table('playlists')->count(),
            DB::table('youtube_write_admissions')->count(),
        ];
    }
}
