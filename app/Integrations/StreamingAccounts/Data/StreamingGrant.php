<?php

namespace App\Integrations\StreamingAccounts\Data;

use LogicException;

final readonly class StreamingGrant
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public array $scopes,
    ) {}

    public function __serialize(): array
    {
        throw new LogicException('Streaming grants cannot be serialized.');
    }
}
