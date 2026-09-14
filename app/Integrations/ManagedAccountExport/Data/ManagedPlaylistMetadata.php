<?php

namespace App\Integrations\ManagedAccountExport\Data;

use App\Integrations\ManagedAccountExport\ManagedExportMarker;
use InvalidArgumentException;

final readonly class ManagedPlaylistMetadata
{
    public function __construct(
        public string $title,
        public string $description,
        public string $marker,
        bool $requireMarker = true,
    ) {
        if ($title === '' || ($requireMarker && ! ManagedExportMarker::appearsExactlyOnce($description, $marker))) {
            throw new InvalidArgumentException('Managed playlist metadata requires a title and one final marker line.');
        }
    }
}
