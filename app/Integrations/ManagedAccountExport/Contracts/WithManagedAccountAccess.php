<?php

namespace App\Integrations\ManagedAccountExport\Contracts;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessResult;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use Closure;

interface WithManagedAccountAccess
{
    /**
     * @param  list<string>  $requiredScopes
     * @param  Closure(ManagedAccessContext): (void|ManagedExportFailureCode)  $callback
     */
    public function handle(
        StreamingProvider $provider,
        string $operationId,
        string $expectedAccountId,
        array $requiredScopes,
        Closure $callback,
    ): ManagedAccessResult;
}
