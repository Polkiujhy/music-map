<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Jobs\SendExportOperationCompletedNotification;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\User;
use App\Notifications\ExportOperationCompleted;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class ExportOperationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_operation_dispatches_only_at_the_sixty_second_threshold(): void
    {
        Queue::fake([SendExportOperationCompletedNotification::class]);
        $short = $this->operation();
        $short->update([
            'status' => ExportOperationStatus::Failed,
            'failure_code' => ExportOperationFailure::AccessDenied,
            'started_at' => now()->subSeconds(59),
            'completed_at' => now(),
        ]);
        Queue::assertNotPushed(
            SendExportOperationCompletedNotification::class,
            fn (SendExportOperationCompletedNotification $job): bool => $job->operationId === $short->operation_id,
        );

        $long = $this->operation();
        $long->update([
            'status' => ExportOperationStatus::Failed,
            'failure_code' => ExportOperationFailure::AccessDenied,
            'started_at' => now()->subSeconds(60),
            'completed_at' => now(),
        ]);
        Queue::assertPushed(
            SendExportOperationCompletedNotification::class,
            1,
        );
        Queue::assertPushed(
            SendExportOperationCompletedNotification::class,
            fn (SendExportOperationCompletedNotification $job): bool => $job->operationId === $long->operation_id,
        );
    }

    public function test_successful_delivery_sets_marker_and_suppresses_ordinary_retries(): void
    {
        Queue::fake();
        Notification::fake();
        $operation = $this->completedOperation();
        $job = new SendExportOperationCompletedNotification($operation->operation_id);

        $job->handle();
        $job->handle();

        Notification::assertSentToTimes($operation->user, ExportOperationCompleted::class, 1);
        Notification::assertSentTo(
            $operation->user,
            ExportOperationCompleted::class,
            function (ExportOperationCompleted $notification) use ($operation): bool {
                $mail = $notification->toMail($operation->user);

                return $notification->operationId === $operation->operation_id
                    && $notification->destinationType === ExportDestinationType::Managed
                    && ($mail->viewData['url'] ?? null) === route('export-operations.show', [
                        'playlist' => $operation->source_playlist_id,
                        'exportOperation' => $operation->operation_id,
                    ]);
            },
        );
        $this->assertNotNull($operation->fresh()->notification_sent_at);
    }

    public function test_parallel_delivery_is_serialized_by_operation_id(): void
    {
        $operation = $this->operation();
        $middleware = (new SendExportOperationCompletedNotification($operation->operation_id))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame(120, $middleware[0]->expiresAfter);
        $this->assertSame(5, $middleware[0]->releaseAfter);
    }

    public function test_delivery_exception_leaves_marker_open_for_retry(): void
    {
        Queue::fake();
        $operation = $this->completedOperation();
        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendNow')->once()->andThrow(new RuntimeException('mail transport failed'));
        });

        try {
            (new SendExportOperationCompletedNotification($operation->operation_id))->handle();
            $this->fail('A failed delivery must fail the job so the queue retries it.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mail transport failed', $exception->getMessage());
        }

        $this->assertNull($operation->fresh()->notification_sent_at);
    }

    public function test_crash_window_after_transport_acceptance_can_deliver_a_duplicate(): void
    {
        Queue::fake();
        $operation = $this->completedOperation();
        $accepted = 0;
        $this->mock(Dispatcher::class, function (MockInterface $mock) use (&$accepted): void {
            $mock->shouldReceive('sendNow')->twice()->andReturnUsing(function () use (&$accepted): never {
                $accepted++;

                throw new RuntimeException('worker crashed after transport acceptance');
            });
        });
        $job = new SendExportOperationCompletedNotification($operation->operation_id);

        foreach ([1, 2] as $attempt) {
            try {
                $job->handle();
            } catch (RuntimeException $exception) {
                $this->assertSame('worker crashed after transport acceptance', $exception->getMessage());
            }
        }

        $this->assertSame(2, $accepted, 'At-least-once delivery permits a duplicate before the marker is persisted.');
        $this->assertNull($operation->fresh()->notification_sent_at);
    }

    private function completedOperation(): ExportOperation
    {
        $operation = $this->operation();
        $operation->update([
            'status' => ExportOperationStatus::Transferred,
            'active_key' => null,
            'started_at' => now()->subSeconds(60),
            'completed_at' => now(),
        ]);

        return $operation->fresh()->load('user');
    }

    private function operation(): ExportOperation
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'source_playlist_id' => fake()->unique()->regexify('PL-[A-Za-z0-9]{16}'),
        ]);
        $review = ExportReview::factory()->for($playlist)->create(['user_id' => $user->id]);

        return ExportOperation::factory()->create([
            'export_review_id' => $review->id,
            'user_id' => $user->id,
            'source_playlist_id' => $playlist->id,
            'destination_type' => ExportDestinationType::Managed,
        ])->load('user');
    }
}
