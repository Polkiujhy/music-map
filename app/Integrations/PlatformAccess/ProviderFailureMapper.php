<?php

namespace App\Integrations\PlatformAccess;

use Illuminate\Http\Client\Response;

final class ProviderFailureMapper
{
    public const STAGE_REFRESH = 'refresh';

    public const STAGE_IDENTITY = 'identity';

    public const STAGE_FIXTURE = 'fixture';

    public const STAGE_INSERT = 'insert';

    public static function transport(string $provider, string $principal): ProbeFailure
    {
        return self::failure('provider-unavailable', $provider, $principal);
    }

    public static function invalidResponse(string $provider, string $principal): ProbeFailure
    {
        return self::failure('provider-response-invalid', $provider, $principal);
    }

    public static function accountMismatch(string $provider, string $principal): ProbeFailure
    {
        return self::failure('account-mismatch', $provider, $principal);
    }

    public static function scopeMismatch(string $provider, string $principal): ProbeFailure
    {
        return self::failure('scope-mismatch', $provider, $principal);
    }

    public static function fixtureInvalid(string $provider, string $principal): ProbeFailure
    {
        return self::failure('fixture-invalid', $provider, $principal);
    }

    public static function rotationRequired(string $provider, string $principal): ProbeFailure
    {
        return self::failure('refresh-token-rotation-required', $provider, $principal);
    }

    public static function cleanupFailed(string $provider, string $principal): ProbeFailure
    {
        return self::failure('cleanup-failed', $provider, $principal);
    }

    public static function response(
        string $provider,
        string $principal,
        string $stage,
        Response $response,
    ): ProbeFailure {
        $status = $response->status();
        $code = self::safeString($response->json('error.code'))
            ?? self::safeString($response->json('error'));
        $reason = self::safeString($response->json('reason'))
            ?? self::safeString($response->json('error.reason'))
            ?? self::safeString($response->json('error.errors.0.reason'));

        if ($status >= 500) {
            return self::transport($provider, $principal);
        }

        if (self::isQuotaFailure($provider, $code, $reason)) {
            return self::failure('quota-exceeded', $provider, $principal);
        }

        if ($status === 429) {
            return self::failure('rate-limited', $provider, $principal);
        }

        if ($stage === self::STAGE_REFRESH
            && ($status === 401 || $code === 'invalid_grant')) {
            return self::failure('authorization-denied', $provider, $principal);
        }

        if ($status === 401) {
            return self::failure('authorization-denied', $provider, $principal);
        }

        if ($provider === 'spotify' && in_array('PREMIUM_REQUIRED', [$code, $reason], true)) {
            return self::failure('account-requirement-failed', $provider, $principal);
        }

        if ($stage === self::STAGE_INSERT && self::isInvalidFixtureItem($provider, $code, $reason)) {
            return self::fixtureInvalid($provider, $principal);
        }

        if (in_array($stage, [self::STAGE_FIXTURE, self::STAGE_INSERT], true)
            && in_array($status, [403, 404], true)) {
            return self::failure('resource-access-denied', $provider, $principal);
        }

        return self::transport($provider, $principal);
    }

    private static function isQuotaFailure(string $provider, ?string $code, ?string $reason): bool
    {
        return ($provider === 'spotify' && in_array('QUOTA_EXCEEDED', [$code, $reason], true))
            || ($provider === 'youtube'
                && in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true));
    }

    private static function isInvalidFixtureItem(string $provider, ?string $code, ?string $reason): bool
    {
        return ($provider === 'spotify' && in_array($code, ['INVALID_URI', 'INVALID_TRACK_URI'], true))
            || ($provider === 'youtube'
                && in_array($reason, ['videoNotFound', 'videoNotEmbeddable'], true));
    }

    private static function safeString(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 64 ? $value : null;
    }

    private static function failure(string $category, string $provider, string $principal): ProbeFailure
    {
        return ProbeFailure::make($category, $provider, $principal);
    }
}
