<?php

namespace App\Integrations\PlatformAccess;

final readonly class ProbeInvocation
{
    private const REQUIRED_FLAGS = [
        '--write',
        '--format=json',
        '--no-ansi',
        '--no-interaction',
    ];

    private function __construct(
        public string $provider,
        public string $principal,
    ) {}

    public static function fromRawTokens(array $tokens): self|ProbeFailure
    {
        $providerValues = self::optionValues($tokens, '--provider=');
        $principalValues = self::optionValues($tokens, '--principal=');
        $provider = self::normalizedSingleValue($providerValues, PlatformAccessProtocol::PROVIDERS);
        $principal = self::normalizedSingleValue($principalValues, PlatformAccessProtocol::PRINCIPALS);

        if (($tokens[0] ?? null) !== PlatformAccessProtocol::COMMAND || count($tokens) !== 7) {
            return ProbeFailure::make('invalid-invocation', $provider, $principal);
        }

        $remaining = array_slice($tokens, 1);
        $expected = [
            "--provider={$provider}",
            "--principal={$principal}",
            ...self::REQUIRED_FLAGS,
        ];

        if ($provider === null
            || $principal === null
            || count($remaining) !== count(array_unique($remaining, SORT_STRING))
            || self::sorted($remaining) !== self::sorted($expected)) {
            return ProbeFailure::make('invalid-invocation', $provider, $principal);
        }

        return new self($provider, $principal);
    }

    private static function optionValues(array $tokens, string $prefix): array
    {
        return array_values(array_map(
            static fn (string $token): string => substr($token, strlen($prefix)),
            array_filter(
                $tokens,
                static fn (mixed $token): bool => is_string($token) && str_starts_with($token, $prefix),
            ),
        ));
    }

    private static function normalizedSingleValue(array $values, array $allowed): ?string
    {
        return count($values) === 1 && in_array($values[0], $allowed, true)
            ? $values[0]
            : null;
    }

    private static function sorted(array $tokens): array
    {
        sort($tokens, SORT_STRING);

        return $tokens;
    }
}
