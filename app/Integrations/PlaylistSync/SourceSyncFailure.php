<?php

namespace App\Integrations\PlaylistSync;

enum SourceSyncFailure: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case NotFound = 'not-found';
    case RateLimited = 'rate-limited';
    case ProviderUnavailable = 'provider-unavailable';
    case InvalidResponse = 'invalid-response';
    case OverLimit = 'over-limit';
    case OwnerMismatch = 'owner-mismatch';
    case ReconnectRequired = 'reconnect-required';
    case ExternalDrift = 'external-drift';
}
