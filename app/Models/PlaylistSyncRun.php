<?php

namespace App\Models;

use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncTrigger;
use Database\Factories\PlaylistSyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operation_id', 'trigger', 'direction', 'state', 'input_bank_fingerprint',
    'input_source_fingerprint', 'input_provider_revision', 'desired_fingerprint',
    'bank_snapshot', 'source_snapshot', 'checkpoint',
])]
#[Hidden(['checkpoint'])]
class PlaylistSyncRun extends Model
{
    /** @use HasFactory<PlaylistSyncRunFactory> */
    use HasFactory;

    /** @return BelongsTo<PlaylistSynchronization, $this> */
    public function synchronization(): BelongsTo
    {
        return $this->belongsTo(PlaylistSynchronization::class, 'playlist_synchronization_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => PlaylistSyncTrigger::class,
            'direction' => PlaylistSyncDirection::class,
            'bank_snapshot' => 'array',
            'source_snapshot' => 'array',
            'checkpoint' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
