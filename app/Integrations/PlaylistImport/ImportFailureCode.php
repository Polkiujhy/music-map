<?php

namespace App\Integrations\PlaylistImport;

enum ImportFailureCode: string
{
    case InvalidUrl = 'invalid-url';
    case UnsupportedProvider = 'unsupported-provider';
    case UnsupportedItem = 'unsupported-item';
    case LinkedAccountRequired = 'linked-account-required';
    case ReauthorizationRequired = 'reauthorization-required';
    case InsufficientScope = 'insufficient-scope';
    case PlaylistUnavailable = 'playlist-unavailable';
    case PlaylistNotFound = 'playlist-not-found';
    case TooManyItems = 'too-many-items';
    case RateLimited = 'rate-limited';
    case QuotaLimited = 'quota-limited';
    case ProviderUnavailable = 'provider-unavailable';
    case InvalidResponse = 'invalid-response';
}
