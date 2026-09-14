<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PlaylistEditingController extends Controller
{
    public function __invoke(Request $request, string $playlist): View
    {
        abort_unless(ctype_digit($playlist) && (int) $playlist > 0, 404);

        $playlist = $request->user()->playlists()
            ->whereKey($playlist)
            ->firstOrFail();

        return view('playlists.edit', ['playlist' => $playlist]);
    }
}
