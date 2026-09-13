<?php

namespace App\Integrations\StreamingAccounts\Data;

use App\Integrations\StreamingAccounts\StreamingAccessFailure;

final readonly class StreamingAccessResult
{
    private function __construct(
        public bool $successful,
        public ?StreamingAccessFailure $failure,
    ) {}

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(StreamingAccessFailure $failure): self
    {
        return new self(false, $failure);
    }
}
