<?php

namespace App\Integrations\PlatformAccess;

final readonly class TechnicalConfiguration
{
    private const REQUIRED_KEYS = [
        'client_id',
        'client_secret',
        'refresh_token',
        'expected_account_id',
        'account_id',
        'scopes',
    ];

    private function __construct(
        public string $provider,
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $expectedAccountId,
        public array $scopes,
    ) {}

    public static function fromArray(string $provider, array $values): self|ProbeFailure
    {
        if (! PlatformAccessProtocol::isProvider($provider)) {
            return ProbeFailure::make('invalid-invocation', null, 'technical');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (! PlatformAccessProtocol::isRuntimeValue($values[$key] ?? null)) {
                return ProbeFailure::make('invalid-configuration', $provider, 'technical');
            }
        }

        if ($values['expected_account_id'] !== $values['account_id']) {
            return ProbeFailure::make('invalid-configuration', $provider, 'technical');
        }

        $scopes = preg_split('/ +/', trim($values['scopes'])) ?: [];
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);

        $expectedScopes = PlatformAccessProtocol::scopesFor($provider);
        sort($expectedScopes, SORT_STRING);

        if ($scopes !== $expectedScopes) {
            return ProbeFailure::make('invalid-configuration', $provider, 'technical');
        }

        return new self(
            $provider,
            $values['client_id'],
            $values['client_secret'],
            $values['refresh_token'],
            $values['expected_account_id'],
            $scopes,
        );
    }
}
