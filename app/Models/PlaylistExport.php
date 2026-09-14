<?php

namespace App\Models;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use Database\Factories\PlaylistExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'source_playlist_id',
    'target_provider',
    'destination_type',
    'streaming_account_id',
    'target_account_id',
    'target_market',
    'target_generation',
    'target_playlist_id',
])]
class PlaylistExport extends Model
{
    /** @use HasFactory<PlaylistExportFactory> */
    use HasFactory;

    /** @return BelongsTo<Playlist, $this> */
    public function sourcePlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'source_playlist_id');
    }

    /** @return BelongsTo<Playlist, $this> */
    public function targetPlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'target_playlist_id');
    }

    /** @return BelongsTo<StreamingAccount, $this> */
    public function streamingAccount(): BelongsTo
    {
        return $this->belongsTo(StreamingAccount::class);
    }

    /** @return HasMany<PlaylistExportTargetAttempt, $this> */
    public function targetAttempts(): HasMany
    {
        return $this->hasMany(PlaylistExportTargetAttempt::class)->orderBy('generation');
    }

    /** @return HasMany<ExportOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(ExportOperation::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $playlistExport): void {
            $originalTargetPlaylistId = $playlistExport->getRawOriginal('target_playlist_id');

            if ($originalTargetPlaylistId !== null
                && $playlistExport->isDirty('target_playlist_id')
                && (string) $playlistExport->target_playlist_id !== (string) $originalTargetPlaylistId) {
                throw new LogicException('The managed target playlist locator is write-once.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_provider' => StreamingProvider::class,
            'destination_type' => ExportDestinationType::class,
            'target_generation' => 'integer',
        ];
    }
}
