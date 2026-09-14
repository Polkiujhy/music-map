<?php

namespace App\Integrations\PlaylistExport;

use App\Enums\StreamingProvider;

final class ProviderPlaylistUrl
{
    public static function fromId(StreamingProvider $provider, string $providerId): ?string
    {
        if (! self::validId($provider, $providerId)) {
            return null;
        }

        return match ($provider) {
            StreamingProvider::Spotify => 'https://open.spotify.com/playlist/'.$providerId,
            StreamingProvider::YouTube => 'https://www.youtube.com/playlist?list='.$providerId,
        };
    }

    public static function validId(StreamingProvider $provider, string $providerId): bool
    {
        return match ($provider) {
            StreamingProvider::Spotify => preg_match('/\A[A-Za-z0-9]{22}\z/D', $providerId) === 1,
            StreamingProvider::YouTube => preg_match('/\A[A-Za-z0-9_-]{13,64}\z/D', $providerId) === 1,
        };
    }
}
