<?php

namespace App\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\WithManagedAccountAccess as WithManagedAccountAccessContract;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessResult;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\ManagedExportAccessException;
use Closure;
use Throwable;

final readonly class WithManagedAccountAccess implements WithManagedAccountAccessContract
{
    public function __construct(private ManagedExportAccessBroker $broker) {}

    public function handle(
        StreamingProvider $provider,
        string $operationId,
        string $expectedAccountId,
        array $requiredScopes,
        Closure $callback,
    ): ManagedAccessResult {
        $configuration = config('services.managed_export.providers.'.$provider->value);
        if (! is_array($configuration)) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::ConfigurationUnavailable);
        }

        $accountId = $configuration['account_id'] ?? null;
        $configuredScopes = $this->normalizeScopes($configuration['scopes'] ?? null);
        $requiredScopes = $this->normalizeScopes($requiredScopes);
        if (! is_string($accountId)
            || preg_match('/^[^\x00-\x20]{1,255}$/D', $accountId) !== 1
            || str_starts_with($accountId, '__REQUIRED_')) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::ConfigurationUnavailable);
        }
        if ($accountId !== $expectedAccountId) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::AccountMismatch);
        }
        if ($configuredScopes === null) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::ConfigurationUnavailable);
        }
        if ($requiredScopes === null || $configuredScopes !== $requiredScopes) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::RequiredScopeMissing);
        }

        try {
            $access = $this->broker->acquire($provider->value, $operationId);
        } catch (ManagedExportAccessException $exception) {
            return ManagedAccessResult::failure(
                $this->mapBrokerFailure($exception),
                $exception->retryable,
                $exception->retryAfter,
            );
        } catch (Throwable) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::TransportUnavailable);
        }

        if ($access->provider !== $provider->value || $access->operationId !== $operationId) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::InvalidResponse);
        }
        if ($access->expiresAt < now()->addSeconds(390)) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::TransportUnavailable, true);
        }

        $context = new ManagedAccessContext(
            $provider,
            $accountId,
            $access->accessToken,
            $access->expiresAt,
            $access->operationId,
        );

        try {
            $outcome = $callback($context);
        } finally {
            unset($context, $access);
        }

        if ($outcome !== null && ! $outcome instanceof ManagedExportFailureCode) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::InvalidResponse);
        }

        return $outcome instanceof ManagedExportFailureCode
            ? ManagedAccessResult::failure($outcome)
            : ManagedAccessResult::success();
    }

    /** @return null|list<string> */
    private function normalizeScopes(mixed $scopes): ?array
    {
        if (is_string($scopes)) {
            $scopes = preg_split('/\s+/', trim($scopes), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($scopes) || ! array_is_list($scopes)) {
            return null;
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || $scope === '' || preg_match('/\s/', $scope) === 1) {
                return null;
            }
        }
        $normalized = array_values(array_unique($scopes));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function mapBrokerFailure(ManagedExportAccessException $failure): ManagedExportFailureCode
    {
        return match ($failure->category) {
            'caller-denied', 'credential-unavailable' => ManagedExportFailureCode::ConfigurationUnavailable,
            'reauthorization-required' => ManagedExportFailureCode::AuthenticationRequired,
            'scope-mismatch' => ManagedExportFailureCode::RequiredScopeMissing,
            'rate-limited' => ManagedExportFailureCode::RateLimited,
            'quota-exceeded' => ManagedExportFailureCode::QuotaExceeded,
            'rotation-recovery-required' => ManagedExportFailureCode::RefreshRotationRequired,
            'provider-unavailable' => ManagedExportFailureCode::TransportUnavailable,
            default => ManagedExportFailureCode::InvalidResponse,
        };
    }
}
