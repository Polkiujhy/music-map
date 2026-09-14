<?php

namespace App\Http\Controllers;

use App\Actions\Playlists\ImportPlaylist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlaylistReimportController extends Controller
{
    public function __invoke(Request $request, string $playlist, ImportPlaylist $import): RedirectResponse
    {
        abort_unless(ctype_digit($playlist) && (int) $playlist > 0, 404);

        $playlist = $request->user()->playlists()
            ->sourceOnly()
            ->whereKey($playlist)
            ->firstOrFail();

        $request->validate([
            'confirm_reimport' => ['accepted'],
        ], [
            'confirm_reimport.accepted' => 'Potwierdź pełny reimport playlisty.',
        ]);

        $result = $import->handle(
            $request->user(),
            $playlist->canonical_source_url,
            localEditsConfirmed: true,
        );

        if ($result->successful) {
            return to_route('bank.playlists.edit', $playlist)
                ->with('status', 'Playlista została w pełni zaimportowana ponownie. Lokalne zmiany kolejności i usunięcia zostały zastąpione wersją platformy.');
        }

        return to_route('bank.playlists.edit', $playlist)->with(
            'error',
            $result->failureMessage(),
        );
    }
}
