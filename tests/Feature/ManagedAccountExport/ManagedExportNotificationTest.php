<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Jobs\SendManagedExportCompletedNotification;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Notifications\ManagedExportCompleted;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ManagedExportNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_terminal_generation_notifies_only_after_sixty_seconds_and_deduplicates_checkpointed_delivery(): void
    {
        $short = $this->operation(ExportOperationStatus::Succeeded, 59);
        (new SendManagedExportCompletedNotification($short->id, 0))->handle();
        Notification::assertNothingSent();

        $operation = $this->operation(ExportOperationStatus::Succeeded, 60);
        $job = new SendManagedExportCompletedNotification($operation->id, 0);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($operation->id.':0', $job->uniqueId());

        $job->handle();
        $job->handle();

        Notification::assertSentToTimes($operation->user, ManagedExportCompleted::class, 1);
        $this->assertSame(0, $operation->fresh()->notification_generation);
        $this->assertNotNull($operation->fresh()->notification_sent_at);
    }

    public function test_transport_failure_leaves_checkpoint_open_for_queue_retry(): void
    {
        $operation = $this->operation(ExportOperationStatus::Failed, 60);
        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendNow')->once()->andThrow(new RuntimeException('mail transport failed'));
        });

        try {
            (new SendManagedExportCompletedNotification($operation->id, 0))->handle();
            $this->fail('Failed transport must leave the job retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mail transport failed', $exception->getMessage());
        }

        $this->assertNull($operation->fresh()->notification_sent_at);
        $this->assertNull($operation->fresh()->notification_generation);
    }

    public function test_crash_after_send_before_checkpoint_has_a_documented_at_least_once_duplicate_window(): void
    {
        $operation = $this->operation(ExportOperationStatus::PartialFailed, 60);
        $job = new SendManagedExportCompletedNotification($operation->id, 2);
        $operation->update(['retry_generation' => 2]);

        $job->handle();
        ExportOperation::query()->whereKey($operation->getKey())->update([
            'notification_generation' => null,
            'notification_sent_at' => null,
        ]);
        $job->handle();

        Notification::assertSentToTimes($operation->user, ManagedExportCompleted::class, 2);
    }

    public function test_mail_uses_only_owner_scoped_status_link_and_safe_product_copy(): void
    {
        $operation = $this->operation(ExportOperationStatus::Succeeded, 60);
        (new SendManagedExportCompletedNotification($operation->id, 0))->handle();

        Notification::assertSentTo($operation->user, ManagedExportCompleted::class, function ($notification) use ($operation): bool {
            $mail = $notification->toMail($operation->user);
            $url = $mail->viewData['url'] ?? null;

            return $url === route('export-reviews.show', [
                'playlist' => $operation->exportReview->playlist_id,
                'exportReview' => $operation->export_review_id,
            ])
                && ! str_contains((string) $url, $operation->playlistExport->target_account_id)
                && ($mail->viewData['statusLabel'] ?? null) === 'Przeniesiona — zarządzana przez music-map';
        });
    }

    private function operation(ExportOperationStatus $status, int $duration): ExportOperation
    {
        $source = Playlist::factory()->create();
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create();

        return ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create([
            'status' => $status,
            'started_at' => now()->subSeconds($duration),
            'completed_at' => now(),
        ])->load(['user', 'exportReview', 'playlistExport']);
    }
}
