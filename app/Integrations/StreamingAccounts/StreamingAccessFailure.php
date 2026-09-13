<?php

namespace App\Integrations\StreamingAccounts;

enum StreamingAccessFailure: string
{
    case ReconnectRequired = 'reconnect_required';
    case MissingScope = 'missing_scope';
    case StaleCredential = 'stale_credential';
    case RateLimited = 'rate_limited';
    case QuotaExceeded = 'quota_exceeded';
    case TemporarilyUnavailable = 'temporarily_unavailable';
}
