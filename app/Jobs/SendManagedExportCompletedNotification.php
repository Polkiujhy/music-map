<?php

namespace App\Jobs;

use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use App\Notifications\ManagedExportCompleted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendManagedExportCompletedNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $exportOperationId,
        public readonly int $generation,
    ) {}

    public function uniqueId(): string
    {
        return $this->exportOperationId.':'.$this->generation;
    }

    public function handle(): void
    {
        $operation = ExportOperation::query()
            ->with(['user', 'exportReview', 'playlistExport.targetAttempts'])
            ->find($this->exportOperationId);

        if (! $operation instanceof ExportOperation
            || $operation->retry_generation !== $this->generation
            || ! in_array($operation->status, self::terminalStatuses(), true)
            || $operation->started_at === null
            || $operation->completed_at === null
            || $operation->started_at->diffInSeconds($operation->completed_at) < 60
            || ($operation->notification_generation === $this->generation
                && $operation->notification_sent_at !== null)) {
            return;
        }

        $operation->user->notifyNow(new ManagedExportCompleted(
            (string) $operation->getKey(),
            (int) $operation->export_review_id,
            (int) $operation->exportReview->playlist_id,
            $operation->playlistExport->target_provider,
            $operation->status,
            $operation->playlistExport->destination_type,
        ));

        // This checkpoint follows transport acceptance. A worker crash between
        // notifyNow() and this CAS can cause one duplicate, by design.
        ExportOperation::query()
            ->whereKey($operation->getKey())
            ->where('retry_generation', $this->generation)
            ->where(function ($query): void {
                $query->whereNull('notification_generation')
                    ->orWhere('notification_generation', '!=', $this->generation)
                    ->orWhereNull('notification_sent_at');
            })
            ->update([
                'notification_generation' => $this->generation,
                'notification_sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** @return list<ExportOperationStatus> */
    private static function terminalStatuses(): array
    {
        return [
            ExportOperationStatus::Succeeded,
            ExportOperationStatus::Failed,
            ExportOperationStatus::PartialFailed,
            ExportOperationStatus::ManualRecoveryRequired,
            ExportOperationStatus::RecreateRequired,
        ];
    }
}
