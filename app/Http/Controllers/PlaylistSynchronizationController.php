<?php

namespace App\Http\Controllers;

use App\Actions\PlaylistSync\ConfirmPlaylistSynchronization;
use App\Actions\PlaylistSync\PreparePlaylistSynchronization;
use App\Http\Requests\ConfirmPlaylistSynchronizationRequest;
use App\Models\Playlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlaylistSynchronizationController extends Controller
{
    public function prepare(
        Request $request,
        string $playlist,
        PreparePlaylistSynchronization $prepare,
    ): JsonResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);

        return response()->json($prepare->handle($request->user(), $ownedPlaylist));
    }

    public function confirm(
        ConfirmPlaylistSynchronizationRequest $request,
        string $playlist,
        ConfirmPlaylistSynchronization $confirm,
    ): JsonResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $run = $confirm->handle($request->user(), $ownedPlaylist, $request->previewToken());

        return response()->json([
            'synchronization_id' => (int) $run->playlist_synchronization_id,
            'run_id' => (int) $run->getKey(),
            'status' => 'queued',
            'automatic_enabled' => false,
        ], 202);
    }

    private function playlist(Request $request, string $playlist): Playlist
    {
        abort_unless(ctype_digit($playlist) && (int) $playlist > 0, 404);

        return $request->user()->playlists()->whereKey($playlist)->firstOrFail();
    }
}
