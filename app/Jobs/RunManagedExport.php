<?php

namespace App\Jobs;

use App\Actions\ManagedAccountExport\RunManagedExport as RunManagedExportAction;
use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunManagedExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $exportOperationId,
        public readonly int $retryGeneration = 0,
    ) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("managed-export:{$this->exportOperationId}"))
                ->expireAfter(510)
                ->dontRelease(),
        ];
    }

    public function handle(RunManagedExportAction $run): void
    {
        $retryAfter = $run->handle($this->exportOperationId, $this->retryGeneration);

        if ($retryAfter !== null && $this->attempts() < $this->tries) {
            $this->release($retryAfter);

            return;
        }

        $operation = ExportOperation::query()->find($this->exportOperationId);
        if ($operation instanceof ExportOperation
            && $operation->retry_generation === $this->retryGeneration
            && in_array($operation->status, [
                ExportOperationStatus::Succeeded,
                ExportOperationStatus::Failed,
                ExportOperationStatus::PartialFailed,
                ExportOperationStatus::ManualRecoveryRequired,
                ExportOperationStatus::RecreateRequired,
            ], true)) {
            SendManagedExportCompletedNotification::dispatch(
                (string) $operation->getKey(),
                $operation->retry_generation,
            );
        }
    }
}
