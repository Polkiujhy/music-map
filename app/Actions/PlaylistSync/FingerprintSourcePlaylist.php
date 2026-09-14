<?php

namespace App\Actions\PlaylistSync;

use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;

final class FingerprintSourcePlaylist
{
    public const VERSION = 'source-playlist:v1';

    public function handle(SourcePlaylistSnapshot $snapshot): string
    {
        $json = json_encode(
            $snapshot->normalizedItemIdentifiers(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', self::VERSION."\n".$json);
    }
}
