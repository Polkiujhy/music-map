<?php

namespace App\Integrations\ManagedAccountExport\Data;

use InvalidArgumentException;

final readonly class ManagedMarkerLookup
{
    /** @param list<ManagedPlaylistReference> $matches */
    private function __construct(
        public ManagedMarkerLookupStatus $status,
        public array $matches,
    ) {
        if (($status === ManagedMarkerLookupStatus::None && $matches !== [])
            || ($status === ManagedMarkerLookupStatus::One && count($matches) !== 1)
            || ($status === ManagedMarkerLookupStatus::Ambiguous && count($matches) < 2)
            || ($status === ManagedMarkerLookupStatus::Inconclusive && $matches !== [])) {
            throw new InvalidArgumentException('Marker lookup status does not match its candidates.');
        }
    }

    public static function none(): self
    {
        return new self(ManagedMarkerLookupStatus::None, []);
    }

    public static function one(ManagedPlaylistReference $match): self
    {
        return new self(ManagedMarkerLookupStatus::One, [$match]);
    }

    /** @param list<ManagedPlaylistReference> $matches */
    public static function ambiguous(array $matches): self
    {
        return new self(ManagedMarkerLookupStatus::Ambiguous, $matches);
    }

    public static function inconclusive(): self
    {
        return new self(ManagedMarkerLookupStatus::Inconclusive, []);
    }
}
