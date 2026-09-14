<?php

namespace App\Integrations\StreamingAccounts\Data;

use App\Enums\StreamingProvider;
use DateTimeImmutable;
use LogicException;

final readonly class StreamingAccessContext
{
    public function __construct(
        public StreamingProvider $provider,
        public string $providerAccountId,
        public string $accessToken,
        public ?DateTimeImmutable $expiresAt = null,
        public ?int $credentialVersion = null,
    ) {}

    public function __serialize(): array
    {
        throw new LogicException('Streaming access contexts cannot be serialized.');
    }
}
