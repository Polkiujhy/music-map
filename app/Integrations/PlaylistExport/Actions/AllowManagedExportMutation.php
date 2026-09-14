<?php

namespace App\Integrations\PlaylistExport\Actions;

use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;

final readonly class AllowManagedExportMutation implements ExportMutationGuard
{
    public function failure(): ?PlaylistWriteFailure
    {
        return null;
    }
}
