<?php

namespace App\Integrations\ManagedAccountExport\Data;

final readonly class ManagedPlaylistReconciliationResult
{
    private function __construct(
        public bool $exact,
        public ?ManagedPlaylistSnapshot $snapshot,
    ) {}

    public static function exact(ManagedPlaylistSnapshot $snapshot): self
    {
        return new self(true, $snapshot);
    }

    public static function notExact(?ManagedPlaylistSnapshot $snapshot = null): self
    {
        return new self(false, $snapshot);
    }
}
