<?php

namespace App\Models;

use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use Database\Factories\ExportReviewItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'position', 'source_occurrence_id', 'source_catalog_id', 'source_catalog_uri',
    'source_title', 'source_creators', 'source_album', 'source_duration_milliseconds',
    'source_isrc', 'source_is_available', 'match_status', 'target_catalog_id',
    'target_catalog_uri', 'target_title', 'target_creators', 'target_album',
    'target_duration_milliseconds', 'decision',
])]
class ExportReviewItem extends Model
{
    /** @use HasFactory<ExportReviewItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<ExportReview, $this> */
    public function exportReview(): BelongsTo
    {
        return $this->belongsTo(ExportReview::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_creators' => 'array',
            'source_is_available' => 'boolean',
            'match_status' => ExportMatchStatus::class,
            'target_creators' => 'array',
            'decision' => ExportReviewDecision::class,
        ];
    }
}
