<?php

namespace App\Actions\Exports;

use App\Models\ExportOperation;
use App\Models\User;

final readonly class AbandonExportRecovery
{
    public function __construct(private RetryExportOperation $operations) {}

    public function handle(User $owner, ExportOperation $operation, bool $orphanCopiesChecked): ExportOperation
    {
        return $this->operations->abandonRecovery($owner, $operation, $orphanCopiesChecked);
    }
}
