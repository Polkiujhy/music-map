<?php

namespace App\Integrations\PlatformAccess;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProbeFailure
{
    private function __construct(
        public string $category,
        public ?string $provider,
        public ?string $principal,
        public string $correlationId,
    ) {}

    public static function make(
        string $category,
        ?string $provider,
        ?string $principal,
    ): self {
        if (! array_key_exists($category, PlatformAccessProtocol::FAILURE_EXIT_CODES)) {
            throw new InvalidArgumentException('Unsupported platform-access failure category.');
        }

        if ($category !== 'invalid-invocation'
            && (! PlatformAccessProtocol::isProvider($provider)
                || ! PlatformAccessProtocol::isPrincipal($principal))) {
            throw new InvalidArgumentException('A provider and principal are required for this failure category.');
        }

        if ($provider !== null && ! PlatformAccessProtocol::isProvider($provider)) {
            throw new InvalidArgumentException('Failure provider must be normalized.');
        }

        if ($principal !== null && ! PlatformAccessProtocol::isPrincipal($principal)) {
            throw new InvalidArgumentException('Failure principal must be normalized.');
        }

        return new self(
            $category,
            $provider,
            $principal,
            (string) Str::uuid(),
        );
    }

    public function exitCode(): int
    {
        return PlatformAccessProtocol::FAILURE_EXIT_CODES[$this->category];
    }

    public function stdout(): string
    {
        return '';
    }

    public function stderr(): string
    {
        return json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $this->provider,
            'principal' => $this->principal,
            'status' => 'error',
            'category' => $this->category,
            'correlation_id' => $this->correlationId,
            'complete' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
