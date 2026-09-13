<?php

namespace App\Integrations\PlatformAccess;

final readonly class RefreshTokenRotationSink
{
    public function __construct(
        private string $path = PlatformAccessProtocol::REPLACEMENT_PATH,
    ) {}

    public function write(string $provider, string $principal, ?string $refreshToken): ?ProbeFailure
    {
        if ($refreshToken === null) {
            return null;
        }

        if (! PlatformAccessProtocol::isProvider($provider)
            || ! PlatformAccessProtocol::isPrincipal($principal)
            || ! PlatformAccessProtocol::isSecretString($refreshToken)) {
            return ProbeFailure::make('refresh-token-rotation-required', $provider, $principal);
        }

        $document = json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $provider,
            'principal' => $principal,
            'refresh_token' => $refreshToken,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (strlen($document) > PlatformAccessProtocol::MAX_REPLACEMENT_DOCUMENT_BYTES
            || @file_put_contents($this->path, $document, LOCK_EX) !== strlen($document)) {
            return ProbeFailure::make('refresh-token-rotation-required', $provider, $principal);
        }

        return null;
    }
}
