<?php

namespace Tests\Unit\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\PlaylistShareUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaylistShareUrlParserTest extends TestCase
{
    #[DataProvider('acceptedUrls')]
    public function test_it_accepts_only_supported_playlist_share_urls(
        string $url,
        StreamingProvider $provider,
        string $id,
        string $canonicalUrl,
    ): void {
        $reference = (new PlaylistShareUrlParser)->parse($url);

        $this->assertNotInstanceOf(ImportFailureCode::class, $reference);
        $this->assertSame($provider, $reference->provider);
        $this->assertSame($id, $reference->providerPlaylistId);
        $this->assertSame($canonicalUrl, $reference->canonicalUrl);
    }

    public static function acceptedUrls(): array
    {
        return [
            'spotify' => [
                'https://open.spotify.com/playlist/0123456789abcdefghijkl?si=canary',
                StreamingProvider::Spotify,
                '0123456789abcdefghijkl',
                'https://open.spotify.com/playlist/0123456789abcdefghijkl',
            ],
            'youtube' => [
                'https://www.youtube.com/playlist?list=PL_canary-123&si=ignored',
                StreamingProvider::YouTube,
                'PL_canary-123',
                'https://www.youtube.com/playlist?list=PL_canary-123',
            ],
            'youtube music' => [
                'https://music.youtube.com/playlist?list=PL_canary-123',
                StreamingProvider::YouTube,
                'PL_canary-123',
                'https://www.youtube.com/playlist?list=PL_canary-123',
            ],
        ];
    }

    #[DataProvider('hostileUrls')]
    public function test_it_rejects_hostile_or_noncanonical_urls(string $url): void
    {
        $this->assertInstanceOf(ImportFailureCode::class, (new PlaylistShareUrlParser)->parse($url));
    }

    public static function hostileUrls(): array
    {
        return [
            'http' => ['http://open.spotify.com/playlist/0123456789abcdefghijkl'],
            'userinfo' => ['https://user@open.spotify.com/playlist/0123456789abcdefghijkl'],
            'port' => ['https://open.spotify.com:443/playlist/0123456789abcdefghijkl'],
            'fragment' => ['https://open.spotify.com/playlist/0123456789abcdefghijkl#fragment'],
            'lookalike' => ['https://open.spotify.com.example.test/playlist/0123456789abcdefghijkl'],
            'subdomain' => ['https://evil.open.spotify.com/playlist/0123456789abcdefghijkl'],
            'encoded slash' => ['https://open.spotify.com/playlist%2F0123456789abcdefghijkl'],
            'encoded control' => ['https://www.youtube.com/playlist?list=PL%0acanary'],
            'duplicate query' => ['https://www.youtube.com/playlist?list=first&list=second'],
            'unknown query' => ['https://www.youtube.com/playlist?list=PL_canary&utm_source=test'],
            'invalid spotify id' => ['https://open.spotify.com/playlist/too-short'],
            'invalid youtube id' => ['https://www.youtube.com/playlist?list=not.allowed'],
            'overlong' => ['https://www.youtube.com/playlist?list='.str_repeat('a', 480)],
        ];
    }

    public function test_it_distinguishes_an_unknown_provider_from_an_invalid_supported_url(): void
    {
        $this->assertSame(
            ImportFailureCode::UnsupportedProvider,
            (new PlaylistShareUrlParser)->parse('https://example.test/playlist?id=canary'),
        );
    }
}
