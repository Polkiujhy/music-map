<?php

namespace App\Integrations\ManagedExport\Contracts;

use App\Integrations\ManagedExport\Data\ManagedExportAccess;

interface ManagedExportAccessBroker
{
    public function acquire(string $provider, string $operationId): ManagedExportAccess;
}
