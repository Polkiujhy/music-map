<?php

namespace App\Integrations\ManagedAccountExport;

use InvalidArgumentException;

final readonly class ManagedProviderFailure
{
    public function __construct(
        public ManagedExportFailureCode $code,
        public bool $retryable = false,
        public ?int $retryAfter = null,
    ) {
        if (! in_array($code, [
            ManagedExportFailureCode::ConfigurationUnavailable,
            ManagedExportFailureCode::AuthenticationRequired,
            ManagedExportFailureCode::RequiredScopeMissing,
            ManagedExportFailureCode::AccountMismatch,
            ManagedExportFailureCode::RateLimited,
            ManagedExportFailureCode::QuotaExceeded,
            ManagedExportFailureCode::TransportUnavailable,
            ManagedExportFailureCode::InvalidResponse,
            ManagedExportFailureCode::TargetMissing,
            ManagedExportFailureCode::TargetOwnerMismatch,
            ManagedExportFailureCode::TargetMarkerMismatch,
            ManagedExportFailureCode::TargetVisibilityMismatch,
            ManagedExportFailureCode::MetadataRejected,
            ManagedExportFailureCode::ItemRejected,
            ManagedExportFailureCode::AmbiguousMutation,
            // Application fencing can deny a gateway mutation before any HTTP write.
            ManagedExportFailureCode::PersistenceFailure,
        ], true)) {
            throw new InvalidArgumentException('Failure code is outside the managed provider taxonomy.');
        }
        if ($retryAfter !== null && ($retryAfter < 0 || $retryAfter > 3600)) {
            throw new InvalidArgumentException('Retry delay is outside the public bounded contract.');
        }
    }
}
