<?php

namespace Database\Factories;

use App\Enums\ExportMatchStatus;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExportReviewItem> */
class ExportReviewItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'export_review_id' => ExportReview::factory(),
            'position' => 0,
            'source_occurrence_id' => 'canary-occurrence',
            'source_catalog_id' => 'canary-source-id',
            'source_catalog_uri' => 'canary:source:item',
            'source_title' => 'Canary source title',
            'source_creators' => ['Canary source creator'],
            'source_album' => 'Canary source album',
            'source_duration_milliseconds' => 1000,
            'source_isrc' => 'CANARY000001',
            'source_is_available' => true,
            'match_status' => ExportMatchStatus::Matched,
            'target_catalog_id' => 'canary-target-id',
            'target_catalog_uri' => 'canary:target:item',
            'target_title' => 'Canary target title',
            'target_creators' => ['Canary target creator'],
            'target_album' => 'Canary target album',
            'target_duration_milliseconds' => 1000,
            'decision' => null,
        ];
    }
}
