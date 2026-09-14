<?php

namespace App\Actions\Playlists;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\Providers\YouTubePlaylistReader;
use App\Models\Playlist;

final readonly class RefreshYouTubePlaylistMetadata
{
    public function __construct(
        private YouTubePlaylistReader $reader,
        private ReplaceImportedPlaylist $replace,
    ) {}

    public function handle(int $playlistId): Playlist|ImportFailureCode|null
    {
        $playlist = Playlist::query()
            ->whereKey($playlistId)
            ->where('source_provider', StreamingProvider::YouTube->value)
            ->with('user')
            ->first();

        if ($playlist === null) {
            return null;
        }

        $snapshot = $this->reader->read(new PlaylistReference(
            StreamingProvider::YouTube,
            $playlist->source_playlist_id,
            $playlist->canonical_source_url,
        ));

        if ($snapshot instanceof ImportFailureCode) {
            return $snapshot;
        }

        return $this->replace->handle($playlist->user, $snapshot, markImported: false);
    }
}
