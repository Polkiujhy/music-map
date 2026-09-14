<?php

namespace App\Integrations\PlaylistExport\Data;

use App\Integrations\PlaylistExport\PlaylistWriteFailure;

final readonly class PlaylistWriteResult
{
    /**
     * @param  list<string>  $items
     */
    private function __construct(
        public ?string $providerId,
        public ?string $revision,
        public array $items,
        public ?PlaylistWriteFailure $failure,
    ) {}

    /** @param list<string> $items */
    public static function success(string $providerId, ?string $revision, array $items): self
    {
        return new self($providerId, $revision, $items, null);
    }

    public static function failed(PlaylistWriteFailure $failure): self
    {
        return new self(null, null, [], $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
