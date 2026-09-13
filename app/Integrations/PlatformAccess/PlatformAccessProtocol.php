<?php

namespace App\Integrations\PlatformAccess;

final class PlatformAccessProtocol
{
    public const VERSION = 'music-map.platform-access.v1';

    public const COMMAND = 'platform-access:probe';

    public const SESSION_PATH = '/run/secrets/music-map-platform-access/session.json';

    public const REPLACEMENT_PATH = '/run/secrets/music-map-platform-access/replacement.json';

    public const MAX_SESSION_DOCUMENT_BYTES = 512 * 1024;

    public const MAX_REPLACEMENT_DOCUMENT_BYTES = 128 * 1024;

    public const PROVIDERS = ['spotify', 'youtube'];

    public const PRINCIPALS = ['technical', 'tester'];

    public const SCOPES = [
        'spotify' => [
            'playlist-modify-private',
            'playlist-read-private',
            'user-read-private',
        ],
        'youtube' => [
            'https://www.googleapis.com/auth/youtube',
        ],
    ];

    public const CAPABILITIES = [
        'technical' => [
            'identity',
            'read',
            'refresh-token-exchange',
        ],
        'tester' => [
            'cleanup',
            'identity',
            'read',
            'refresh-token-exchange',
            'write',
        ],
    ];

    public const FAILURE_EXIT_CODES = [
        'invalid-invocation' => 2,
        'invalid-configuration' => 2,
        'invalid-session' => 2,
        'provider-unavailable' => 1,
        'provider-response-invalid' => 1,
        'authorization-denied' => 1,
        'account-mismatch' => 1,
        'scope-mismatch' => 1,
        'account-requirement-failed' => 1,
        'resource-access-denied' => 1,
        'fixture-invalid' => 1,
        'rate-limited' => 1,
        'quota-exceeded' => 1,
        'refresh-token-rotation-required' => 1,
        'cleanup-failed' => 1,
    ];

    public static function isProvider(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::PROVIDERS, true);
    }

    public static function isPrincipal(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::PRINCIPALS, true);
    }

    public static function scopesFor(string $provider): array
    {
        return self::SCOPES[$provider] ?? [];
    }

    public static function capabilitiesFor(string $principal): array
    {
        return self::CAPABILITIES[$principal] ?? [];
    }

    public static function isSecretString(mixed $value, int $maximum = 8192): bool
    {
        return self::isBoundedString($value, $maximum)
            && preg_match('/[\x00-\x20]/', $value) !== 1;
    }

    public static function isBoundedString(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && $value !== ''
            && preg_match('//u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= $maximum;
    }

    public static function isRuntimeValue(mixed $value): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && preg_match('/^__REQUIRED_RUNTIME_[A-Z_]+__$/', $value) !== 1;
    }
}
