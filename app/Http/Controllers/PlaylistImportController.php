<?php

namespace App\Http\Controllers;

use App\Actions\Playlists\ImportPlaylist;
use App\Http\Requests\ImportPlaylistRequest;
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
            $result->failureMessage(),
        );
    }
}
