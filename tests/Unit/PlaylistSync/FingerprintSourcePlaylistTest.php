<?php

namespace Tests\Unit\PlaylistSync;

use App\Actions\PlaylistSync\FingerprintSourcePlaylist;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use PHPUnit\Framework\TestCase;

class FingerprintSourcePlaylistTest extends TestCase
{
    public function test_it_normalizes_identifiers_and_ignores_provider_revision(): void
    {
        $fingerprint = new FingerprintSourcePlaylist;

        $first = new SourcePlaylistSnapshot([' track-a ', 'track-b'], 'revision-one');
        $second = new SourcePlaylistSnapshot(['track-a', 'track-b'], 'revision-two');

        $this->assertSame($fingerprint->handle($first), $fingerprint->handle($second));
    }

    public function test_it_preserves_order_and_duplicates(): void
    {
        $fingerprint = new FingerprintSourcePlaylist;
        $ordered = $fingerprint->handle(new SourcePlaylistSnapshot(['track-a', 'track-b', 'track-a']));
        $reordered = $fingerprint->handle(new SourcePlaylistSnapshot(['track-a', 'track-a', 'track-b']));
        $withoutDuplicate = $fingerprint->handle(new SourcePlaylistSnapshot(['track-a', 'track-b']));

        $this->assertNotSame($ordered, $reordered);
        $this->assertNotSame($ordered, $withoutDuplicate);
    }

    public function test_v1_is_part_of_the_fingerprint_contract(): void
    {
        $snapshot = new SourcePlaylistSnapshot(['track-a', 'track-b']);
        $json = json_encode(['track-a', 'track-b'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(
            hash('sha256', "source-playlist:v1\n".$json),
            (new FingerprintSourcePlaylist)->handle($snapshot),
        );
        $this->assertNotSame(
            hash('sha256', "source-playlist:v2\n".$json),
            (new FingerprintSourcePlaylist)->handle($snapshot),
        );
    }
}
