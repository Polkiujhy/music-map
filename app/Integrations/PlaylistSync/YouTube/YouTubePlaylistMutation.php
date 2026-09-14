<?php

namespace App\Integrations\PlaylistSync\YouTube;

use InvalidArgumentException;

final readonly class YouTubePlaylistMutation
{
    public const DELETE = 'delete';

    public const INSERT = 'insert';

    public const UPDATE_POSITION = 'update-position';

    public function __construct(
        public string $type,
        public ?string $providerItemId,
        public ?string $catalogId,
        public ?int $position,
        public string $beforeFingerprint,
        public string $afterFingerprint,
    ) {
        if (! in_array($type, [self::DELETE, self::INSERT, self::UPDATE_POSITION], true)) {
            throw new InvalidArgumentException('Unknown YouTube playlist mutation type.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (string) ($value['type'] ?? ''),
            isset($value['providerItemId']) ? (string) $value['providerItemId'] : null,
            isset($value['catalogId']) ? (string) $value['catalogId'] : null,
            isset($value['position']) ? (int) $value['position'] : null,
            (string) ($value['beforeFingerprint'] ?? ''),
            (string) ($value['afterFingerprint'] ?? ''),
        );
    }
}
