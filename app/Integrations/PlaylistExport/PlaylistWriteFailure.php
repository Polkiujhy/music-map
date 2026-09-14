<?php

namespace App\Integrations\PlaylistExport;

enum PlaylistWriteFailure: string
{
    case AdmissionLimit = 'admission-limit';
    case AdmissionUnavailable = 'admission-unavailable';
    case ReconnectRequired = 'reconnect-required';
    case MissingScope = 'missing-scope';
    case StaleCredential = 'stale-credential';
    case AccessDenied = 'access-denied';
    case TargetDeleted = 'target-deleted';
    case RateLimited = 'rate-limited';
    case QuotaLimited = 'quota-limited';
    case TemporaryFailure = 'temporary-failure';
    case InvalidResponse = 'invalid-response';
    case UnsupportedDuplicate = 'unsupported-duplicate';
    case AmbiguousCreate = 'ambiguous-create';
    case RecoveryScanIncomplete = 'recovery-scan-incomplete';
}
