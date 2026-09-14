<?php

namespace App\Integrations\PlaylistExport\Actions;

use App\Enums\ExportDestinationType;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\ManagedExportAccessException;
use App\Integrations\PlaylistExport\Contracts\WithExportAccess;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Models\ExportOperation;
use Closure;
use DateTimeImmutable;
use Throwable;

final class WithManagedExportAccess implements WithExportAccess
{
    public const IO_BUDGET_SECONDS = 360;

    public const EXPIRY_SAFETY_MARGIN_SECONDS = 30;

    public const MINIMUM_REMAINING_LIFETIME_SECONDS = self::IO_BUDGET_SECONDS + self::EXPIRY_SAFETY_MARGIN_SECONDS;

    private readonly Closure $clock;

    public function __construct(
        private readonly ManagedExportAccessBroker $broker,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
    }

    public function handle(ExportOperation $operation, Closure $callback): ?PlaylistWriteFailure
    {
        if ($operation->destination_type !== ExportDestinationType::Managed) {
            return PlaylistWriteFailure::AccessDenied;
        }

        try {
            $access = $this->broker->acquire(
                $operation->target_provider->value,
                $operation->operation_id,
            );
        } catch (ManagedExportAccessException $exception) {
            return $this->mapFailure($exception);
        } catch (Throwable) {
            // An unvalidated transport failure never authorizes automatic retry.
            return PlaylistWriteFailure::InvalidResponse;
        }

        if ($access->provider !== $operation->target_provider->value
            || $access->operationId !== $operation->operation_id) {
            return PlaylistWriteFailure::InvalidResponse;
        }

        $minimumExpiry = ($this->clock)()->modify('+'.self::MINIMUM_REMAINING_LIFETIME_SECONDS.' seconds');
        if ($access->expiresAt < $minimumExpiry) {
            return PlaylistWriteFailure::TemporaryFailure;
        }

        return $callback($access->accessToken, new AllowManagedExportMutation);
    }

    private function mapFailure(ManagedExportAccessException $exception): PlaylistWriteFailure
    {
        if (! $exception->retryable) {
            return match ($exception->category) {
                'reauthorization-required' => PlaylistWriteFailure::ReconnectRequired,
                'scope-mismatch' => PlaylistWriteFailure::MissingScope,
                'caller-denied' => PlaylistWriteFailure::AccessDenied,
                default => PlaylistWriteFailure::InvalidResponse,
            };
        }

        return match ($exception->category) {
            'rate-limited' => PlaylistWriteFailure::RateLimited,
            'quota-exceeded' => PlaylistWriteFailure::QuotaLimited,
            default => PlaylistWriteFailure::TemporaryFailure,
        };
    }
}
