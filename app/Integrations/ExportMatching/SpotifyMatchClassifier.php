<?php

namespace App\Integrations\ExportMatching;

use App\Integrations\ExportMatching\Data\CatalogCandidate;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;

final class SpotifyMatchClassifier
{
    /** @param list<CatalogCandidate> $candidates */
    public function classify(SourceTrack $source, array $candidates): MatchResult
    {
        if ($candidates === []) {
            return MatchResult::unavailable();
        }

        $scored = array_map(fn (CatalogCandidate $candidate): array => [
            'candidate' => $candidate,
            'score' => $this->score($source, $candidate),
        ], $candidates);
        usort($scored, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $best = $scored[0];

        if ($best['score'] < 60) {
            return MatchResult::unavailable();
        }

        $ambiguous = isset($scored[1]) && $scored[1]['score'] >= $best['score'] - 3;

        return $best['score'] >= 90 && ! $ambiguous
            ? MatchResult::matched($best['candidate'])
            : MatchResult::suspicious($best['candidate']);
    }

    private function score(SourceTrack $source, CatalogCandidate $candidate): int
    {
        if ($source->isrc !== null && $candidate->isrc !== null
            && strtoupper($source->isrc) === strtoupper($candidate->isrc)) {
            return 100;
        }

        $sourceTitle = MatchNormalizer::text($source->title);
        $candidateTitle = MatchNormalizer::text($candidate->title);
        $score = $sourceTitle !== '' && $sourceTitle === $candidateTitle
            ? 55
            : ($sourceTitle !== '' && str_contains($candidateTitle, $sourceTitle) ? 45 : 0);
        $sourceArtists = MatchNormalizer::names($source->creators);
        $candidateArtists = MatchNormalizer::names($candidate->creators);
        $score += $sourceArtists !== [] && $sourceArtists === $candidateArtists ? 30 : 0;

        if ($source->durationMilliseconds !== null && $candidate->durationMilliseconds !== null) {
            $delta = abs($source->durationMilliseconds - $candidate->durationMilliseconds);
            $score += $delta <= 3000 ? 10 : ($delta <= 10000 ? 3 : -15);
        }

        if (MatchNormalizer::hasVariantMarker($candidate->title)
            && ! MatchNormalizer::hasVariantMarker($source->title ?? '')) {
            $score -= 25;
        }

        return $score;
    }
}
