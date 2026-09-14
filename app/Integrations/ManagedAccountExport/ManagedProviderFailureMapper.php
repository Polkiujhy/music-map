<?php

namespace App\Integrations\ManagedAccountExport;

use Illuminate\Http\Client\Response;

final class ManagedProviderFailureMapper
{
    public static function response(
        Response $response,
        ?ManagedExportFailureCode $rejected = null,
        ManagedExportFailureCode $notFound = ManagedExportFailureCode::TargetMissing,
    ): ManagedProviderFailure {
        $reason = $response->json('error.errors.0.reason')
            ?? $response->json('error.reason')
            ?? $response->json('error.status');

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded', 'QUOTA_EXCEEDED'], true)) {
            return new ManagedProviderFailure(ManagedExportFailureCode::QuotaExceeded);
        }
        if ($response->status() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'RATE_LIMIT_EXCEEDED'], true)) {
            $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

            return new ManagedProviderFailure(
                ManagedExportFailureCode::RateLimited,
                true,
                is_int($retryAfter) && $retryAfter >= 0 ? min($retryAfter, 3600) : null,
            );
        }

        return match ($response->status()) {
            401 => new ManagedProviderFailure(ManagedExportFailureCode::AuthenticationRequired),
            403 => new ManagedProviderFailure(ManagedExportFailureCode::RequiredScopeMissing),
            404 => new ManagedProviderFailure($notFound),
            default => $response->serverError()
                ? new ManagedProviderFailure(ManagedExportFailureCode::TransportUnavailable, true)
                : new ManagedProviderFailure($rejected ?? ManagedExportFailureCode::InvalidResponse),
        };
    }

    public static function transport(bool $mutationStarted = false): ManagedProviderFailure
    {
        return new ManagedProviderFailure(
            $mutationStarted
                ? ManagedExportFailureCode::AmbiguousMutation
                : ManagedExportFailureCode::TransportUnavailable,
            ! $mutationStarted,
        );
    }
}
