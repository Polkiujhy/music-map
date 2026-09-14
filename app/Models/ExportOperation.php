<?php

namespace App\Models;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\StreamingProvider;
use Database\Factories\ExportOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operation_id', 'target_provider', 'destination_type', 'target_account_id',
    'target_market', 'playlist_name', 'playlist_description', 'source_fingerprint',
    'status', 'failure_code', 'attempt_count', 'active_key',
    'provider_mutation_started_at', 'started_at', 'completed_at', 'notification_sent_at',
])]
class ExportOperation extends Model
{
    /** @use HasFactory<ExportOperationFactory> */
    use HasFactory;

    public static function activeKey(int $sourcePlaylistId, StreamingProvider $provider, string $targetAccountId): string
    {
        return hash('sha256', implode('|', [$sourcePlaylistId, $provider->value, $targetAccountId]));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ExportReview, $this> */
    public function exportReview(): BelongsTo
    {
        return $this->belongsTo(ExportReview::class);
    }

    /** @return BelongsTo<Playlist, $this> */
    public function sourcePlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'source_playlist_id');
    }

    /** @return BelongsTo<PlaylistExportLink, $this> */
    public function playlistExportLink(): BelongsTo
    {
        return $this->belongsTo(PlaylistExportLink::class);
    }

    /** @return BelongsTo<StreamingAccount, $this> */
    public function streamingAccount(): BelongsTo
    {
        return $this->belongsTo(StreamingAccount::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_provider' => StreamingProvider::class,
            'destination_type' => ExportDestinationType::class,
            'status' => ExportOperationStatus::class,
            'failure_code' => ExportOperationFailure::class,
            'attempt_count' => 'integer',
            'provider_mutation_started_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'notification_sent_at' => 'immutable_datetime',
        ];
    }
}
