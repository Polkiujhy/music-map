<?php

namespace App\Models;

use App\Enums\StreamingProvider;
use Database\Factories\PlaylistExportTargetAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'playlist_export_id',
    'target_provider',
    'target_account_id',
    'generation',
    'marker',
    'status',
    'create_started_at',
    'create_completed_at',
    'provider_playlist_id',
    'canonical_url',
])]
class PlaylistExportTargetAttempt extends Model
{
    /** @use HasFactory<PlaylistExportTargetAttemptFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ABANDONED = 'abandoned';

    /** @return BelongsTo<PlaylistExport, $this> */
    public function playlistExport(): BelongsTo
    {
        return $this->belongsTo(PlaylistExport::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            foreach (['playlist_export_id', 'target_provider', 'target_account_id', 'generation', 'marker'] as $attribute) {
                if ($attempt->isDirty($attribute)) {
                    throw new LogicException("The target attempt {$attribute} is immutable.");
                }
            }

            foreach (['provider_playlist_id', 'canonical_url'] as $attribute) {
                if ($attempt->getRawOriginal($attribute) !== null && $attempt->isDirty($attribute)) {
                    throw new LogicException("The target attempt {$attribute} is write-once.");
                }
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_provider' => StreamingProvider::class,
            'generation' => 'integer',
            'create_started_at' => 'immutable_datetime',
            'create_completed_at' => 'immutable_datetime',
        ];
    }
}
