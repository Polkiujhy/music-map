<?php

namespace App\Jobs;

use App\Models\ExportOperation;
use App\Notifications\ExportOperationCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class SendExportOperationCompletedNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $operationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-operation-notification:{$this->operationId}"))
                ->expireAfter(120)
                ->releaseAfter(5),
        ];
    }

    public function handle(): void
    {
        $operation = ExportOperation::query()
            ->with('user')
            ->where('operation_id', $this->operationId)
            ->first();

        if (! $operation instanceof ExportOperation || ! $operation->shouldSendCompletionNotification()) {
            return;
        }

        $operation->user->notifyNow(new ExportOperationCompleted(
            $operation->operation_id,
            (int) $operation->source_playlist_id,
            $operation->target_provider,
            $operation->destination_type,
            $operation->status,
        ));

        ExportOperation::query()
            ->whereKey($operation->getKey())
            ->whereNull('notification_sent_at')
            ->update([
                'notification_sent_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
