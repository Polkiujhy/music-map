<?php

namespace App\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ImportResult
{
    private function __construct(
        public string $correlationId,
        public bool $successful,
        public ?int $playlistId,
        public bool $refreshed,
        public ?ImportFailureCode $failureCode,
        public ?StreamingProvider $provider,
    ) {}

    public static function imported(int $playlistId, ?string $correlationId = null): self
    {
        return self::success($playlistId, false, $correlationId);
    }

    public static function refreshed(int $playlistId, ?string $correlationId = null): self
    {
        return self::success($playlistId, true, $correlationId);
    }

    public static function failure(
        ImportFailureCode $failureCode,
        ?StreamingProvider $provider = null,
        ?string $correlationId = null,
    ): self {
        return new self(
            self::correlationId($correlationId),
            false,
            null,
            false,
            $failureCode,
            $provider,
        );
    }

    public function failureMessage(): ?string
    {
        if ($this->successful) {
            return null;
        }

        return "Nie udało się zaimportować playlisty. Identyfikator błędu: {$this->correlationId}.";
    }

    private static function success(int $playlistId, bool $refreshed, ?string $correlationId): self
    {
        if ($playlistId < 1) {
            throw new InvalidArgumentException('A persisted playlist ID is required.');
        }

        return new self(
            self::correlationId($correlationId),
            true,
            $playlistId,
            $refreshed,
            null,
            null,
        );
    }

    private static function correlationId(?string $correlationId): string
    {
        $correlationId ??= (string) Str::uuid();

        if (! Str::isUuid($correlationId)) {
            throw new InvalidArgumentException('Correlation ID must be a UUID.');
        }

        return $correlationId;
    }
}
