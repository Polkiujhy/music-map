<?php

namespace App\Integrations\ExportMatching\Contracts;

use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\MatchingFailure;

interface CatalogSearch
{
    public function search(
        SourceTrack $source,
        StreamingProvider $provider,
        ?string $market,
    ): MatchResult|MatchingFailure;
}
