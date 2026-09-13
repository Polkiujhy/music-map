<?php

namespace App\Integrations\StreamingAccounts;

enum StreamingOAuthFailure: string
{
    case AuthorizationDenied = 'authorization_denied';
    case AccessUnavailable = 'access_unavailable';
    case MissingScope = 'missing_scope';
    case InvalidResponse = 'invalid_response';
    case RateLimited = 'rate_limited';
    case QuotaExceeded = 'quota_exceeded';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case AccountConflict = 'account_conflict';
    case UnlinkRequired = 'unlink_required';
}
