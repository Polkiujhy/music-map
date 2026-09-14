<?php

namespace App\Integrations\ExportMatching;

enum MatchingFailure: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case RateLimited = 'rate-limited';
    case QuotaLimited = 'quota-limited';
    case InvalidResponse = 'invalid-response';
    case TemporarilyUnavailable = 'temporarily-unavailable';
}
