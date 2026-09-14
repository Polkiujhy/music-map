<?php

namespace Tests\Unit\Integrations\ExportMatching;

use App\Enums\ExportMatchStatus;
use App\Integrations\ExportMatching\Data\CatalogCandidate;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\SpotifyMatchClassifier;
use App\Integrations\ExportMatching\YouTubeMatchClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MatchClassifierTest extends TestCase
{
    public function test_source_cache_fingerprint_normalizes_matching_fields(): void
    {
        $first = new SourceTrack(
            0,
            'source-a',
            '  A Song! ',
            ['Second Artist', 'First Artist'],
            ' An Album ',
            180000,
            'GB-ABC-123',
            true,
        );
        $equivalent = new SourceTrack(
            12,
            'different-source-id',
            'a song',
            ['first artist', 'SECOND-ARTIST'],
            'an album',
            180000,
            'gb abc 123',
            true,
        );

        $this->assertSame($first->fingerprint(), $equivalent->fingerprint());
        $this->assertNotSame(
            $first->fingerprint(),
            new SourceTrack(0, null, 'A different song', [], null, 180000, null, true)->fingerprint(),
        );
    }

    public function test_spotify_exact_isrc_is_a_confident_match(): void
    {
        $result = (new SpotifyMatchClassifier)->classify(
            $this->source(isrc: 'GBABC1234567'),
            [$this->candidate(isrc: 'gbabc1234567', title: 'Different title')],
        );

        $this->assertSame(ExportMatchStatus::Matched, $result->status);
    }

    public function test_spotify_normalizes_title_all_artists_and_duration(): void
    {
        $source = $this->source(title: '  A Song! ', creators: ['Second Artist', 'First Artist'], duration: 180000);
        $candidate = $this->candidate(title: 'a song', creators: ['first artist', 'SECOND ARTIST'], duration: 182999);

        $this->assertSame(ExportMatchStatus::Matched, (new SpotifyMatchClassifier)->classify($source, [$candidate])->status);
    }

    #[DataProvider('variants')]
    public function test_spotify_marks_cover_remix_and_live_variants_suspicious(string $variant): void
    {
        $source = $this->source();
        $candidate = $this->candidate(title: 'Canary Song '.$variant);

        $this->assertSame(ExportMatchStatus::Suspicious, (new SpotifyMatchClassifier)->classify($source, [$candidate])->status);
    }

    public static function variants(): array
    {
        return [['cover'], ['remix'], ['live']];
    }

    public function test_spotify_marks_an_ambiguous_best_result_suspicious(): void
    {
        $candidate = $this->candidate();
        $result = (new SpotifyMatchClassifier)->classify($this->source(), [$candidate, $candidate]);

        $this->assertSame(ExportMatchStatus::Suspicious, $result->status);
    }

    public function test_classifiers_return_unavailable_only_when_no_candidate_is_credible(): void
    {
        $source = $this->source();
        $weak = $this->candidate(title: 'Unrelated', creators: ['Nobody'], duration: 500000);

        $this->assertSame(ExportMatchStatus::Unavailable, (new SpotifyMatchClassifier)->classify($source, [])->status);
        $this->assertSame(ExportMatchStatus::Unavailable, (new SpotifyMatchClassifier)->classify($source, [$weak])->status);
        $this->assertSame(ExportMatchStatus::Unavailable, (new YouTubeMatchClassifier)->classify($source, [])->status);
    }

    public function test_weaker_youtube_metadata_stays_suspicious_until_evidence_is_strong(): void
    {
        $source = $this->source();
        $officialVideo = $this->candidate(
            title: 'Canary Song — Official Video',
            creators: ['Unrelated uploader'],
            duration: 184000,
        );

        $this->assertSame(
            ExportMatchStatus::Suspicious,
            (new YouTubeMatchClassifier)->classify($source, [$officialVideo])->status,
        );
    }

    private function source(
        ?string $title = 'Canary Song',
        array $creators = ['Canary Artist'],
        ?int $duration = 180000,
        ?string $isrc = null,
    ): SourceTrack {
        return new SourceTrack(0, 'source-id', $title, $creators, 'Canary Album', $duration, $isrc, true);
    }

    private function candidate(
        string $title = 'Canary Song',
        array $creators = ['Canary Artist'],
        ?int $duration = 180000,
        ?string $isrc = null,
    ): CatalogCandidate {
        return new CatalogCandidate('target-id', 'provider:target-id', $title, $creators, 'Canary Album', $duration, $isrc);
    }
}
