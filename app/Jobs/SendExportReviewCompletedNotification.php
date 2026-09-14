<?php

namespace App\Jobs;

use App\Enums\ExportReviewStatus;
use App\Models\ExportReview;
use App\Notifications\ExportReviewCompleted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendExportReviewCompletedNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $exportReviewId) {}

    public function uniqueId(): string
    {
        return (string) $this->exportReviewId;
    }

    public function handle(): void
    {
        $review = ExportReview::query()->with('user')->find($this->exportReviewId);

        if (! $review instanceof ExportReview
            || ! in_array($review->status, [ExportReviewStatus::Ready, ExportReviewStatus::Failed], true)
            || $review->started_at === null
            || $review->completed_at === null
            || $review->started_at->diffInSeconds($review->completed_at) < 60
            || $review->notification_sent_at !== null) {
            return;
        }

        $review->user->notifyNow(new ExportReviewCompleted(
            (int) $review->getKey(),
            (int) $review->playlist_id,
            $review->target_provider,
            $review->status,
        ));

        ExportReview::query()
            ->whereKey($review->getKey())
            ->whereNull('notification_sent_at')
            ->update(['notification_sent_at' => now(), 'updated_at' => now()]);
    }
}
