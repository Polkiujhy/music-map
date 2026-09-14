<?php

namespace App\Integrations\PlaylistExport\Contracts;

use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Models\ExportOperation;
use Closure;

interface WithExportAccess
{
    /**
     * @param  Closure(string, ExportMutationGuard): (?PlaylistWriteFailure)  $callback
     */
    public function handle(ExportOperation $operation, Closure $callback): ?PlaylistWriteFailure;
}
