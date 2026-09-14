<?php

namespace App\Integrations\ExportMatching;

use App\Integrations\ExportMatching\Data\CatalogCandidate;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;

final class YouTubeMatchClassifier
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

        if ($best['score'] < 45) {
            return MatchResult::unavailable();
        }

        $ambiguous = isset($scored[1]) && $scored[1]['score'] >= $best['score'] - 5;

        return $best['score'] >= 85 && ! $ambiguous
            ? MatchResult::matched($best['candidate'])
            : MatchResult::suspicious($best['candidate']);
    }

    private function score(SourceTrack $source, CatalogCandidate $candidate): int
    {
        $sourceTitle = MatchNormalizer::text($source->title);
        $candidateTitle = MatchNormalizer::text($candidate->title);
        $score = $sourceTitle !== '' && $sourceTitle === $candidateTitle
            ? 55
            : ($sourceTitle !== '' && str_contains($candidateTitle, $sourceTitle) ? 40 : 0);

        foreach (MatchNormalizer::names($source->creators) as $artist) {
            if (str_contains($candidateTitle, $artist)
                || in_array($artist, MatchNormalizer::names($candidate->creators), true)) {
                $score += 25;
            }
        }

        if ($source->durationMilliseconds !== null && $candidate->durationMilliseconds !== null) {
            $delta = abs($source->durationMilliseconds - $candidate->durationMilliseconds);
            $score += $delta <= 5000 ? 10 : ($delta <= 15000 ? 3 : -15);
        }

        if (MatchNormalizer::hasVariantMarker($candidate->title)
            && ! MatchNormalizer::hasVariantMarker($source->title ?? '')) {
            $score -= 30;
        }

        return min($score, 100);
    }
}
