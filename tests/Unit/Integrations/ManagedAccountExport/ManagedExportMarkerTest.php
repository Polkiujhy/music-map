<?php

namespace Tests\Unit\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\ManagedExportMarker;
use App\Integrations\ManagedAccountExport\ManagedPlaylistMetadataFactory;
use PHPUnit\Framework\TestCase;

class ManagedExportMarkerTest extends TestCase
{
    public function test_marker_has_full_random_128_bit_ascii_shape_without_domain_ids(): void
    {
        $first = ManagedExportMarker::generate();
        $second = ManagedExportMarker::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame(32, strlen(str_replace('-', '', $first)));
    }

    public function test_unicode_is_bounded_while_reserved_input_is_removed_and_suffix_is_never_truncated(): void
    {
        $marker = '01234567-89ab-cdef-0123-456789abcdef';
        $factory = new ManagedPlaylistMetadataFactory;
        $metadata = $factory->make(
            StreamingProvider::Spotify,
            str_repeat('Żółć 🎵 ', 30),
            "Opis 🌍\nspoof ".ManagedExportMarker::PREFIX."aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa\nkoniec",
            $marker,
        );

        $this->assertLessThanOrEqual(ManagedPlaylistMetadataFactory::SPOTIFY_TITLE_LIMIT, mb_strlen($metadata->title));
        $this->assertLessThanOrEqual(ManagedPlaylistMetadataFactory::SPOTIFY_DESCRIPTION_LIMIT, mb_strlen($metadata->description));
        $this->assertSame(1, substr_count($metadata->description, ManagedExportMarker::PREFIX));
        $this->assertStringEndsWith(ManagedExportMarker::line($marker), $metadata->description);
        $this->assertTrue(ManagedExportMarker::appearsExactlyOnce($metadata->description, $marker));
    }

    public function test_youtube_limit_is_declared_and_preserves_exact_final_marker(): void
    {
        $marker = 'fedcba98-7654-3210-fedc-ba9876543210';
        $metadata = (new ManagedPlaylistMetadataFactory)->make(
            StreamingProvider::YouTube,
            'Nazwa',
            str_repeat('ą', 6000),
            $marker,
        );

        $this->assertSame(ManagedPlaylistMetadataFactory::YOUTUBE_DESCRIPTION_LIMIT, mb_strlen($metadata->description));
        $this->assertStringEndsWith(ManagedExportMarker::line($marker), $metadata->description);
    }
}
