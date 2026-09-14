<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;

final readonly class ManagedAccessResult
{
    private function __construct(
        public bool $successful,
        public ?ManagedExportFailureCode $failure,
    ) {}

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(ManagedExportFailureCode $failure): self
    {
        return new self(false, $failure);
    }
}
