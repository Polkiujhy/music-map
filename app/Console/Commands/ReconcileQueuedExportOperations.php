<?php

namespace App\Console\Commands;

use App\Enums\ExportOperationStatus;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use Illuminate\Console\Command;

final class ReconcileQueuedExportOperations extends Command
{
    protected $signature = 'exports:reconcile-queued';

    protected $description = 'Redispatch durable export operations left queued after commit';

    public function handle(): int
    {
        ExportOperation::query()
            ->where('status', ExportOperationStatus::Queued->value)
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->chunkById(100, function ($operations): void {
                $operations->each(fn (ExportOperation $operation) => ExecuteExportOperation::dispatch($operation->operation_id));
            });

        return self::SUCCESS;
    }
}
