<?php

namespace App\Integrations\PlatformAccess;

use InvalidArgumentException;

final readonly class ProbeResult
{
    private function __construct(
        public string $provider,
        public string $principal,
        public array $capabilities,
    ) {}

    public static function success(string $provider, string $principal): self
    {
        if (! PlatformAccessProtocol::isProvider($provider)
            || ! PlatformAccessProtocol::isPrincipal($principal)) {
            throw new InvalidArgumentException('A normalized provider and principal are required.');
        }

        return new self(
            $provider,
            $principal,
            PlatformAccessProtocol::capabilitiesFor($principal),
        );
    }

    public function exitCode(): int
    {
        return 0;
    }

    public function stdout(): string
    {
        return json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $this->provider,
            'principal' => $this->principal,
            'status' => 'ok',
            'capabilities' => $this->capabilities,
            'complete' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    public function stderr(): string
    {
        return '';
    }
}
