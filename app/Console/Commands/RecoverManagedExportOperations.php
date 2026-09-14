<?php

namespace App\Console\Commands;

use App\Actions\ManagedAccountExport\PublishManagedExport;
use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use Illuminate\Console\Command;

final class RecoverManagedExportOperations extends Command
{
    private const BATCH_SIZE = 100;

    private const QUEUED_GRACE_SECONDS = 30;

    private const PROCESSING_LEASE_SECONDS = 510;

    protected $signature = 'managed-exports:recover';

    protected $description = 'Republish orphaned managed export operations from durable intent';

    public function handle(PublishManagedExport $publisher): int
    {
        ExportOperation::query()
            ->where(function ($query): void {
                $query->where(function ($queued): void {
                    $queued->where('status', ExportOperationStatus::Queued->value)
                        ->where('updated_at', '<=', now()->subSeconds(self::QUEUED_GRACE_SECONDS))
                        ->where(function ($retry): void {
                            $retry->whereNull('retry_available_at')->orWhere('retry_available_at', '<=', now());
                        });
                })->orWhere(function ($processing): void {
                    $processing->where('status', ExportOperationStatus::Processing->value)
                        ->where(function ($heartbeat): void {
                            $heartbeat->whereNull('heartbeat_at')
                                ->orWhere('heartbeat_at', '<=', now()->subSeconds(self::PROCESSING_LEASE_SECONDS));
                        });
                });
            })
            ->where(function ($lease): void {
                $lease->whereNull('job_publication_lease_until')
                    ->orWhere('job_publication_lease_until', '<=', now());
            })
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id')
            ->each(fn (string $operationId) => $publisher->publish($operationId));

        return self::SUCCESS;
    }
}
