<?php

namespace App\Console\Commands;

use App\Actions\Exports\RecoverStaleExportOperation;
use App\Enums\ExportOperationStatus;
use App\Models\ExportOperation;
use Illuminate\Console\Command;

final class ReconcileStaleExportOperations extends Command
{
    protected $signature = 'exports:reconcile-stale';

    protected $description = 'Classify and safely redispatch stale processing export operations';

    public function handle(RecoverStaleExportOperation $recover): int
    {
        ExportOperation::query()
            ->where('status', ExportOperationStatus::Processing->value)
            ->where('updated_at', '<=', now()->subSeconds($recover->staleAfterSeconds()))
            ->orderBy('id')
            ->chunkById(100, function ($operations) use ($recover): void {
                $operations->each(fn (ExportOperation $operation) => $recover->recover($operation));
            });

        return self::SUCCESS;
    }
}
