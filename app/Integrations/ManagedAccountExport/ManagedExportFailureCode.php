<?php

namespace App\Integrations\ManagedAccountExport;

enum ManagedExportFailureCode: string
{
    case ConfigurationUnavailable = 'configuration-unavailable';
    case AuthenticationRequired = 'authentication-required';
    case RequiredScopeMissing = 'required-scope-missing';
    case AccountMismatch = 'account-mismatch';
    case RefreshRotationRequired = 'refresh-rotation-required';
    case RateLimited = 'rate-limited';
    case QuotaExceeded = 'quota-exceeded';
    case TransportUnavailable = 'transport-unavailable';
    case InvalidResponse = 'invalid-response';
    case TargetMissing = 'target-missing';
    case TargetOwnerMismatch = 'target-owner-mismatch';
    case TargetMarkerMismatch = 'target-marker-mismatch';
    case TargetVisibilityMismatch = 'target-visibility-mismatch';
    case MetadataRejected = 'metadata-rejected';
    case ItemRejected = 'item-rejected';
    case AmbiguousMutation = 'ambiguous-mutation';
    case PersistenceFailure = 'persistence-failure';
}
