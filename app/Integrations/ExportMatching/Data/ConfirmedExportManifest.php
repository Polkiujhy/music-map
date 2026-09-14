<?php

namespace App\Integrations\ExportMatching\Data;

use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use LogicException;

final readonly class ConfirmedExportManifest
{
    /**
     * @param  list<array{position: int, catalog_id: string, catalog_uri: string}>  $items
     */
    public function __construct(
        public int $reviewId,
        public int $playlistId,
        public StreamingProvider $targetProvider,
        public ExportDestinationType $destinationType,
        public string $targetAccountId,
        public ?string $targetMarket,
        public string $sourceFingerprint,
        public array $items,
    ) {}

    public static function fromConfirmedReview(ExportReview $review): self
    {
        if ($review->status !== ExportReviewStatus::Confirmed || $review->confirmed_at === null) {
            throw new LogicException('Only a confirmed export review can be used as a manifest.');
        }

        $items = ($review->relationLoaded('items') ? $review->items : $review->items()->get())
            ->filter(fn ($item): bool => $item->match_status !== ExportMatchStatus::Unavailable
                && ($item->decision ?? ExportReviewDecision::Keep) === ExportReviewDecision::Keep)
            ->map(function ($item): array {
                if ($item->target_catalog_id === null || $item->target_catalog_uri === null) {
                    throw new LogicException('A confirmed manifest item must have an exact target.');
                }

                return [
                    'position' => (int) $item->position,
                    'catalog_id' => $item->target_catalog_id,
                    'catalog_uri' => $item->target_catalog_uri,
                ];
            })
            ->values()
            ->all();

        return new self(
            (int) $review->getKey(),
            (int) $review->playlist_id,
            $review->target_provider,
            $review->destination_type,
            $review->target_account_id,
            $review->target_market,
            $review->source_fingerprint,
            $items,
        );
    }
}
