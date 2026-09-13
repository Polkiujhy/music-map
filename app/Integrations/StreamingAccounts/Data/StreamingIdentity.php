<?php

namespace App\Integrations\StreamingAccounts\Data;

final readonly class StreamingIdentity
{
    public function __construct(
        public string $accountId,
        public ?string $label = null,
    ) {}
}
