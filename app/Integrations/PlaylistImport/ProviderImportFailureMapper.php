<?php

namespace App\Integrations\PlaylistImport;

use Illuminate\Http\Client\Response;

final class ProviderImportFailureMapper
{
    public static function transport(): ImportFailureCode
    {
        return ImportFailureCode::ProviderUnavailable;
    }

    public static function response(Response $response): ImportFailureCode
    {
        $status = $response->status();
        $reason = self::boundedReason($response->json('error.errors.0.reason'))
            ?? self::boundedReason($response->json('error.reason'))
            ?? self::boundedReason($response->json('error.status'))
            ?? self::boundedReason($response->json('error.code'));

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'QUOTA_EXCEEDED'], true)) {
            return ImportFailureCode::QuotaLimited;
        }

        if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return ImportFailureCode::RateLimited;
        }

        if ($status === 404) {
            return ImportFailureCode::PlaylistNotFound;
        }

        if ($status === 403) {
            return ImportFailureCode::PlaylistUnavailable;
        }

        if ($status >= 500) {
            return ImportFailureCode::ProviderUnavailable;
        }

        return ImportFailureCode::InvalidResponse;
    }

    private static function boundedReason(mixed $reason): ?string
    {
        return is_string($reason) && strlen($reason) <= 64 ? $reason : null;
    }
}
