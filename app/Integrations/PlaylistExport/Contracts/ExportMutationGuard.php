<?php

namespace App\Integrations\PlaylistExport\Contracts;

use App\Integrations\PlaylistExport\PlaylistWriteFailure;

interface ExportMutationGuard
{
    /** Return null when the next provider mutation is still allowed. */
    public function failure(): ?PlaylistWriteFailure;
}
