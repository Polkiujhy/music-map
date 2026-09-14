<?php

namespace App\Actions\ExportReviews;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;

final readonly class ResolvedExportDestination
{
    public function __construct(
        public StreamingProvider $provider,
        public ExportDestinationType $type,
        public ?int $streamingAccountId,
        public string $accountId,
        public ?string $market,
    ) {}
}
