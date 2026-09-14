<?php

namespace App\Actions\Exports;

use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RecoverStaleExportOperation
{
    public function staleAfterSeconds(): int
    {
        $connection = (string) config('queue.default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", ExecuteExportOperation::RETRY_AFTER_FLOOR);

        return max(
            ExecuteExportOperation::TIMEOUT_SECONDS,
            ExecuteExportOperation::OVERLAP_LEASE_SECONDS,
            $retryAfter,
        );
    }

    public function recover(ExportOperation $operation): bool
    {
        $dispatch = DB::transaction(function () use ($operation): ?string {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->first();
            if (! $locked instanceof ExportOperation
                || $locked->status !== ExportOperationStatus::Processing
                || $locked->updated_at->isAfter(now()->subSeconds($this->staleAfterSeconds()))) {
                return null;
            }

            $locked->forceFill([
                'status' => $locked->provider_mutation_started_at === null
                    ? ExportOperationStatus::Failed
                    : ExportOperationStatus::Incomplete,
                'failure_code' => ExportOperationFailure::TemporaryFailure,
                'completed_at' => now(),
            ])->save();

            return $locked->attempt_count < ExecuteExportOperation::MAX_ATTEMPTS
                ? $locked->operation_id
                : null;
        });

        if ($dispatch !== null) {
            ExecuteExportOperation::dispatch($dispatch);
        }

        return $dispatch !== null;
    }

    public function fail(string $operationId, Throwable $exception): void
    {
        DB::transaction(function () use ($operationId): void {
            $locked = ExportOperation::query()->where('operation_id', $operationId)->lockForUpdate()->first();
            if (! $locked instanceof ExportOperation || $locked->status->isTerminal()) {
                return;
            }
            if ($locked->status !== ExportOperationStatus::Processing) {
                return;
            }

            $locked->forceFill([
                'status' => $locked->provider_mutation_started_at === null
                    ? ExportOperationStatus::Failed
                    : ExportOperationStatus::Incomplete,
                'failure_code' => ExportOperationFailure::TemporaryFailure,
                'completed_at' => now(),
            ])->save();
        });
    }
}
