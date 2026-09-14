<?php

namespace App\Integrations\StreamingAccounts\Data;

use DateTimeImmutable;
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
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function __serialize(): array
    {
        throw new LogicException('Streaming grants cannot be serialized.');
    }
}
