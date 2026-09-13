<?php

namespace App\Enums;

enum StreamingProvider: string
{
    case Spotify = 'spotify';
    case YouTube = 'youtube';

    /**
     * @return list<string>
     */
    public function requiredScopes(): array
    {
        return match ($this) {
            self::Spotify => [
                'playlist-modify-private',
                'playlist-read-collaborative',
                'playlist-read-private',
                'user-read-private',
            ],
            self::YouTube => ['https://www.googleapis.com/auth/youtube'],
        };
    }
}
