<?php

namespace App\Integrations\PlaylistExport\Data;

use App\Integrations\PlaylistExport\PlaylistWriteFailure;

final readonly class CreateRecoveryResult
{
    private function __construct(
        public ?string $providerId,
        public ?PlaylistWriteFailure $failure,
    ) {}

    public static function recovered(string $providerId): self
    {
        return new self($providerId, null);
    }

    public static function failed(PlaylistWriteFailure $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
