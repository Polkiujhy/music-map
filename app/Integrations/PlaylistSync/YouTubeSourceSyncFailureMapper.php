<?php

namespace App\Integrations\PlaylistSync;

use Illuminate\Http\Client\Response;

final class YouTubeSourceSyncFailureMapper
{
    public static function response(Response $response): SourceSyncFailure
    {
        $status = $response->status();
        $reason = self::boundedReason($response->json('error.errors.0.reason'))
            ?? self::boundedReason($response->json('error.reason'))
            ?? self::boundedReason($response->json('error.status'))
            ?? self::boundedReason($response->json('error.code'));

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'QUOTA_EXCEEDED'], true)) {
            return SourceSyncFailure::QuotaLimited;
        }

        if ($status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return SourceSyncFailure::RateLimited;
        }

        return match (true) {
            $status === 401 => SourceSyncFailure::Unauthorized,
            $status === 403 => SourceSyncFailure::Forbidden,
            $status === 404 => SourceSyncFailure::NotFound,
            $status >= 500 => SourceSyncFailure::ProviderUnavailable,
            default => SourceSyncFailure::InvalidResponse,
        };
    }

    private static function boundedReason(mixed $reason): ?string
    {
        return is_string($reason) && strlen($reason) <= 64 ? $reason : null;
    }
}
