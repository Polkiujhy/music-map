<?php

namespace App\Integrations\PlaylistSync\Data;

use InvalidArgumentException;

final readonly class SourcePlaylistSnapshot
{
    /**
     * @param  list<string>  $itemIdentifiers
     */
    public function __construct(
        public array $itemIdentifiers,
        public ?string $providerRevision = null,
    ) {
        if (! array_is_list($itemIdentifiers)) {
            throw new InvalidArgumentException('Source playlist item identifiers must be ordered.');
        }

        foreach ($itemIdentifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Source playlist item identifiers must be non-empty strings.');
            }
        }
    }

    /** @return list<string> */
    public function normalizedItemIdentifiers(): array
    {
        return array_map(
            static fn (string $identifier): string => trim($identifier),
            $this->itemIdentifiers,
        );
    }
}
