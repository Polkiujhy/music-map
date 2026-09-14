<?php

namespace App\Integrations\ManagedAccountExport\Data;

use InvalidArgumentException;

final readonly class ManagedPlaylistItem
{
    public function __construct(
        public string $catalogId,
        public string $catalogUri,
        public int $position,
        public ?string $occurrenceId = null,
    ) {
        if ($catalogId === '' || $catalogUri === '' || $position < 0) {
            throw new InvalidArgumentException('Managed playlist items require identifiers and a non-negative position.');
        }
    }
}
