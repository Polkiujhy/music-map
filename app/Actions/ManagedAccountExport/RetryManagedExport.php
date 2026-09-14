<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RetryManagedExport
{
    public function __construct(
        private PublishManagedExport $publisher,
        private GuardHistoricalExportAccount $accountGuard,
    ) {}

    public function handle(User $user, ExportOperation $operation): ExportOperation
    {
        $operation = DB::transaction(function () use ($user, $operation): ExportOperation {
            $locked = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('user_id', $user->getKey())
                ->with('playlistExport')
                ->lockForUpdate()
                ->firstOrFail();

            $this->accountGuard->handle($user, $locked->playlistExport);

            if (! in_array($locked->status, [ExportOperationStatus::Failed, ExportOperationStatus::PartialFailed], true)) {
                throw ValidationException::withMessages([
                    'operation' => 'Tę operację można ponowić dopiero po zakończonym lub częściowym niepowodzeniu.',
                ]);
            }

            if ($locked->retry_available_at !== null && $locked->retry_available_at->isFuture()) {
                throw ValidationException::withMessages([
                    'operation' => 'Ponowienie nie jest jeszcze dostępne. Spróbuj po wskazanym czasie.',
                ]);
            }

            $locked->forceFill([
                'status' => ExportOperationStatus::Queued,
                'failure_code' => null,
                'retry_generation' => $locked->retry_generation + 1,
                'automatic_claim_count' => 0,
                'completed_at' => null,
                'heartbeat_at' => null,
                'job_publication_lease_until' => null,
                'job_published_at' => null,
                'retry_available_at' => null,
                'manual_recovery_requested_at' => null,
            ])->save();

            $this->publisher->afterCommit((string) $locked->getKey());

            return $locked;
        });

        return $operation->refresh();
    }
}
