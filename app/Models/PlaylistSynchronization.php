<?php

namespace App\Models;

use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use Database\Factories\PlaylistSynchronizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'streaming_account_id', 'status', 'automatic_enabled',
    'baseline_bank_fingerprint', 'baseline_source_fingerprint',
    'baseline_provider_revision', 'last_checked_at', 'last_succeeded_at',
    'next_check_at', 'last_outcome', 'last_failure_code',
])]
class PlaylistSynchronization extends Model
{
    /** @use HasFactory<PlaylistSynchronizationFactory> */
    use HasFactory;

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

    /** @return HasMany<PlaylistSyncRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(PlaylistSyncRun::class);
    }

    /** @return HasOne<PlaylistSyncRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(PlaylistSyncRun::class)->latestOfMany();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PlaylistSyncStatus::class,
            'automatic_enabled' => 'boolean',
            'last_checked_at' => 'immutable_datetime',
            'last_succeeded_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'last_outcome' => PlaylistSyncOutcome::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
