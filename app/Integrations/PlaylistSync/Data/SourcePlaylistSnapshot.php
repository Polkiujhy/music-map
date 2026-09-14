<?php

namespace App\Integrations\PlaylistSync\Data;

use InvalidArgumentException;

final readonly class SourcePlaylistSnapshot
{
    /**
     * @param  list<string>  $itemIdentifiers
     * @param  list<string|null>  $providerItemIdentifiers
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public array $itemIdentifiers,
        public ?string $providerRevision = null,
        public ?string $ownerAccountId = null,
        public array $providerItemIdentifiers = [],
        public array $items = [],
    ) {
        if (! array_is_list($itemIdentifiers)) {
            throw new InvalidArgumentException('Source playlist item identifiers must be ordered.');
        }

        foreach ($itemIdentifiers as $identifier) {
            if (! is_string($identifier) || trim($identifier) === '') {
                throw new InvalidArgumentException('Source playlist item identifiers must be non-empty strings.');
            }
        }

        if (count($itemIdentifiers) > 20) {
            throw new InvalidArgumentException('A source playlist snapshot cannot contain more than 20 items.');
        }

        if (! array_is_list($providerItemIdentifiers)
            || ($providerItemIdentifiers !== [] && count($providerItemIdentifiers) !== count($itemIdentifiers))) {
            throw new InvalidArgumentException('Provider item identifiers must align with source items.');
        }

        foreach ($providerItemIdentifiers as $identifier) {
            if ($identifier !== null && (! is_string($identifier) || trim($identifier) === '')) {
                throw new InvalidArgumentException('Provider item identifiers must be null or non-empty strings.');
            }
        }

        if (! array_is_list($items) || ($items !== [] && count($items) !== count($itemIdentifiers))) {
            throw new InvalidArgumentException('Source item details must align with source identifiers.');
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
