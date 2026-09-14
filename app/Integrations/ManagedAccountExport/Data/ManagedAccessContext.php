<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use Closure;
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
        private ?Closure $mutationGuard = null,
        public bool $requireTargetMarker = true,
    ) {}

    public function mutationFailure(): ?ManagedExportFailureCode
    {
        if ($this->expiresAt <= now()->addSeconds(30)) {
            return ManagedExportFailureCode::AuthenticationRequired;
        }

        return $this->mutationGuard === null ? null : ($this->mutationGuard)();
    }

    public function withMutationGuard(Closure $guard): self
    {
        return new self($this->provider, $this->providerAccountId, $this->accessToken,
            $this->expiresAt, $this->operationId,
            fn (): ?ManagedExportFailureCode => $this->mutationFailure() ?? $guard(), $this->requireTargetMarker);
    }

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
