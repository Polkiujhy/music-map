<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use InvalidArgumentException;

final readonly class ManagedAccessResult
{
    private function __construct(
        public bool $successful,
        public ?ManagedExportFailureCode $failure,
        public bool $retryable,
        public ?int $retryAfter,
    ) {
        if ($retryAfter !== null && ($retryAfter < 0 || $retryAfter > 3600)) {
            throw new InvalidArgumentException('Retry delay is outside the public bounded contract.');
        }
    }

    public static function success(): self
    {
        return new self(true, null, false, null);
    }

    public static function failure(
        ManagedExportFailureCode $failure,
        bool $retryable = false,
        ?int $retryAfter = null,
    ): self {
        return new self(false, $failure, $retryable, $retryAfter);
    }
}
