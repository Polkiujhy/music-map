<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Enums\StreamingProvider;
use DateTimeImmutable;
use LogicException;

final readonly class ManagedAccessContext
{
    public function __construct(
        public StreamingProvider $provider,
        public string $providerAccountId,
        #[\SensitiveParameter]
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
        public string $operationId,
    ) {}

    public function __serialize(): array
    {
        throw new LogicException('Managed account access contexts cannot be serialized.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'providerAccountId' => $this->providerAccountId,
            'expiresAt' => $this->expiresAt,
            'operationId' => $this->operationId,
        ];
    }
}
