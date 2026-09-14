<?php

namespace App\Actions\Exports;

use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RetryExportOperation
{
    public function handle(User $owner, ExportOperation $operation): ExportOperation
    {
        return DB::transaction(function () use ($owner, $operation): ExportOperation {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->user_id !== (int) $owner->getKey()) {
                abort(404);
            }
            if (! in_array($locked->status, [ExportOperationStatus::Failed, ExportOperationStatus::Incomplete], true)
                || $locked->active_key === null
                || $locked->failure_code === null
                || in_array($locked->failure_code, [
                    ExportOperationFailure::TargetDeleted,
                    ExportOperationFailure::UnsupportedDuplicate,
                    ExportOperationFailure::RecoveryAbandoned,
                ], true)) {
                throw ValidationException::withMessages(['operation' => 'Tej operacji nie można ponowić.']);
            }

            $locked->forceFill([
                'status' => ExportOperationStatus::Queued,
                'attempt_count' => 0,
                'completed_at' => null,
            ])->save();

            $operationId = $locked->operation_id;
            DB::afterCommit(static fn () => ExecuteExportOperation::dispatch($operationId));

            return $locked;
        });
    }

    public function abandonRecovery(User $owner, ExportOperation $operation, bool $orphanCopiesChecked): ExportOperation
    {
        if (! $orphanCopiesChecked) {
            throw ValidationException::withMessages([
                'orphan_copies_checked' => 'Potwierdź sprawdzenie i usunięcie osieroconych kopii.',
            ]);
        }

        return DB::transaction(function () use ($owner, $operation): ExportOperation {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->user_id !== (int) $owner->getKey()) {
                abort(404);
            }
            if (! in_array($locked->status, [ExportOperationStatus::Failed, ExportOperationStatus::Incomplete], true)
                || $locked->failure_code !== ExportOperationFailure::AmbiguousCreate) {
                throw ValidationException::withMessages(['operation' => 'Ta operacja nie oczekuje na porzucenie recovery.']);
            }

            $locked->forceFill([
                'status' => ExportOperationStatus::Failed,
                'failure_code' => ExportOperationFailure::RecoveryAbandoned,
                'active_key' => null,
                'completed_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
