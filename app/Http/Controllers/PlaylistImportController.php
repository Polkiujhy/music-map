<?php

namespace App\Http\Controllers;

use App\Actions\Playlists\ImportPlaylist;
use App\Http\Requests\ImportPlaylistRequest;
use App\Integrations\PlaylistImport\ImportFailureCode;
use Illuminate\Http\RedirectResponse;

class PlaylistImportController extends Controller
{
    public function store(ImportPlaylistRequest $request, ImportPlaylist $import): RedirectResponse
    {
        $result = $import->handle($request->user(), $request->string('playlist_url')->toString());

        if ($result->successful) {
            return to_route('bank.index')->with(
                'status',
                $result->refreshed
                    ? 'Playlista została odświeżona w Twoim banku.'
                    : 'Playlista została dodana do Twojego banku.',
            );
        }

        return to_route('bank.index')->with(
            'error',
            $this->failureMessage($result->failureCode, $result->correlationId),
        );
    }

    private function failureMessage(?ImportFailureCode $code, string $correlationId): string
    {
        $message = match ($code) {
            ImportFailureCode::InvalidUrl,
            ImportFailureCode::UnsupportedProvider => 'Podaj prawidłowy link HTTPS do playlisty Spotify lub YouTube.',
            ImportFailureCode::LinkedAccountRequired => 'Połącz konto Spotify i spróbuj ponownie.',
            ImportFailureCode::ReauthorizationRequired => 'Połącz ponownie konto streamingowe i spróbuj jeszcze raz.',
            ImportFailureCode::InsufficientScope => 'Połącz konto ponownie, udzielając wymaganych uprawnień.',
            ImportFailureCode::PlaylistUnavailable => 'Playlista jest prywatna lub niedostępna. Sprawdź jej widoczność.',
            ImportFailureCode::PlaylistNotFound => 'Nie znaleziono playlisty. Sprawdź link i spróbuj ponownie.',
            ImportFailureCode::TooManyItems => 'Playlista ma więcej niż 20 pozycji. Wybierz krótszą playlistę.',
            ImportFailureCode::RateLimited => 'YouTube ograniczył liczbę żądań. Spróbuj ponownie później.',
            ImportFailureCode::QuotaLimited => 'Limit YouTube został wyczerpany. Spróbuj ponownie później.',
            ImportFailureCode::UnsupportedItem => 'Playlista zawiera nieobsługiwaną pozycję. Wybierz inną playlistę.',
            ImportFailureCode::InvalidResponse => 'YouTube zwrócił nieprawidłowe dane. Spróbuj ponownie później.',
            ImportFailureCode::ProviderUnavailable, null => 'YouTube jest chwilowo niedostępny. Spróbuj ponownie później.',
        };

        return "{$message} Identyfikator błędu: {$correlationId}.";
    }
}
