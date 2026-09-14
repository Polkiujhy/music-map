<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Jobs\RunManagedExport;
use App\Models\ExportOperation;
use Illuminate\Support\Facades\DB;

final readonly class PublishManagedExport
{
    public const PUBLICATION_LEASE_SECONDS = 120;

    public function afterCommit(string $operationId): void
    {
        DB::afterCommit(fn () => $this->publish($operationId));
    }

    /**
     * Claim the durable intent before enqueueing it. A crash after the claim is
     * recovered once the lease expires; a crash after enqueue but before the
     * checkpoint may produce another payload, which the operation claim absorbs.
     */
    public function publish(string $operationId): bool
    {
        $generation = DB::transaction(function () use ($operationId): ?int {
            $operation = ExportOperation::query()->whereKey($operationId)->lockForUpdate()->first();

            if (! $operation instanceof ExportOperation
                || ! in_array($operation->status, [ExportOperationStatus::Queued, ExportOperationStatus::Processing], true)
                || ($operation->job_publication_lease_until !== null && $operation->job_publication_lease_until->isFuture())) {
                return null;
            }

            $operation->forceFill([
                'job_publication_lease_until' => now()->addSeconds(self::PUBLICATION_LEASE_SECONDS),
            ])->save();

            return $operation->retry_generation;
        });

        if ($generation === null) {
            return false;
        }

        RunManagedExport::dispatch($operationId, $generation);

        ExportOperation::query()
            ->whereKey($operationId)
            ->where('retry_generation', $generation)
            ->update(['job_published_at' => now(), 'updated_at' => now()]);

        return true;
    }
}
