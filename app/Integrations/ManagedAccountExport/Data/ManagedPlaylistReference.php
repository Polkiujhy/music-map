<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Enums\StreamingProvider;
use InvalidArgumentException;

final readonly class ManagedPlaylistReference
{
    public function __construct(
        public StreamingProvider $provider,
        public string $providerPlaylistId,
        public string $canonicalUrl,
        public string $ownerAccountId,
    ) {
        if ($providerPlaylistId === '' || $ownerAccountId === ''
            || filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || ! str_starts_with($canonicalUrl, 'https://')) {
            throw new InvalidArgumentException('Managed playlist references must be complete and HTTPS.');
        }
    }
}
