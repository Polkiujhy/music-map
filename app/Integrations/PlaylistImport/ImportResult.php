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

        $providerName = $this->provider === StreamingProvider::Spotify ? 'Spotify' : 'YouTube';
        $message = match ($this->failureCode) {
            ImportFailureCode::InvalidUrl,
            ImportFailureCode::UnsupportedProvider => 'Podaj prawidłowy link HTTPS do playlisty Spotify lub YouTube.',
            ImportFailureCode::LinkedAccountRequired => 'Połącz konto Spotify i spróbuj ponownie.',
            ImportFailureCode::ReauthorizationRequired => 'Połącz ponownie konto streamingowe i spróbuj jeszcze raz.',
            ImportFailureCode::InsufficientScope => 'Połącz konto ponownie, udzielając wymaganych uprawnień.',
            ImportFailureCode::PlaylistUnavailable => 'Playlista jest prywatna lub niedostępna. Sprawdź jej widoczność.',
            ImportFailureCode::PlaylistNotFound => 'Nie znaleziono playlisty. Sprawdź link i spróbuj ponownie.',
            ImportFailureCode::TooManyItems => 'Playlista ma więcej niż 20 pozycji. Wybierz krótszą playlistę.',
            ImportFailureCode::RateLimited => "{$providerName} ograniczył liczbę żądań. Spróbuj ponownie później.",
            ImportFailureCode::QuotaLimited => "Limit {$providerName} został wyczerpany. Spróbuj ponownie później.",
            ImportFailureCode::UnsupportedItem => 'Playlista zawiera nieobsługiwaną pozycję. Wybierz inną playlistę.',
            ImportFailureCode::InvalidResponse => "{$providerName} zwrócił nieprawidłowe dane. Spróbuj ponownie później.",
            ImportFailureCode::LocalEditsConfirmationRequired => 'Ta playlista zawiera lokalne zmiany. Otwórz jej właścicielski edytor i użyj świadomego reimportu.',
            ImportFailureCode::ProviderUnavailable, null => "{$providerName} jest chwilowo niedostępny. Spróbuj ponownie później.",
        };

        return "{$message} Identyfikator błędu: {$this->correlationId}.";
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
