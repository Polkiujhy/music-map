<?php

namespace App\Enums;

enum ExportOperationFailure: string
{
    case AdmissionLimit = 'admission-limit';
    case AdmissionUnavailable = 'admission-unavailable';
    case ReconnectRequired = 'reconnect-required';
    case MissingScope = 'missing-scope';
    case StaleCredential = 'stale-credential';
    case RateLimited = 'rate-limited';
    case QuotaLimited = 'quota-limited';
    case TemporaryFailure = 'temporary-failure';
    case AccessDenied = 'access-denied';
    case InvalidResponse = 'invalid-response';
    case TargetDeleted = 'target-deleted';
    case UnsupportedDuplicate = 'unsupported-duplicate';
    case AmbiguousCreate = 'ambiguous-create';
    case RecoveryScanIncomplete = 'recovery-scan-incomplete';
    case RecoveryAbandoned = 'recovery-abandoned';
}
