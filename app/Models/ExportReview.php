<?php

namespace App\Models;

use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use Database\Factories\ExportReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'target_provider', 'destination_type', 'streaming_account_id', 'target_account_id',
    'target_market', 'source_fingerprint', 'status', 'failure_code', 'correlation_id',
    'started_at', 'completed_at', 'expires_at', 'confirmed_at', 'notification_sent_at',
])]
class ExportReview extends Model
{
    /** @use HasFactory<ExportReviewFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Playlist, $this> */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /** @return BelongsTo<StreamingAccount, $this> */
    public function streamingAccount(): BelongsTo
    {
        return $this->belongsTo(StreamingAccount::class);
    }

    /** @return HasMany<ExportReviewItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExportReviewItem::class)->orderBy('position');
    }

    /** @return HasOne<ExportOperation, $this> */
    public function exportOperation(): HasOne
    {
        return $this->hasOne(ExportOperation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_provider' => StreamingProvider::class,
            'destination_type' => ExportDestinationType::class,
            'status' => ExportReviewStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'notification_sent_at' => 'immutable_datetime',
        ];
    }
}
