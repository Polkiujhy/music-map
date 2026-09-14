<?php

namespace App\Models;

use App\Enums\ExportDestinationType;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use Database\Factories\PlaylistExportLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['provider', 'destination_type', 'target_account_id', 'active_key', 'retired_at'])]
class PlaylistExportLink extends Model
{
    /** @use HasFactory<PlaylistExportLinkFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $link): void {
            $source = Playlist::query()->find($link->source_playlist_id);
            $target = Playlist::query()->find($link->target_playlist_id);

            if (! $source instanceof Playlist || ! $target instanceof Playlist
                || $source->role !== PlaylistRole::Source
                || $target->role !== PlaylistRole::ExportTarget
                || (int) $source->user_id !== (int) $link->user_id
                || (int) $target->user_id !== (int) $link->user_id
                || (int) $source->getKey() === (int) $target->getKey()) {
                throw new LogicException('An export link must connect distinct source and target playlists owned by one user.');
            }

            $link->active_key = $link->retired_at === null
                ? self::activeKey((int) $source->getKey(), $link->provider, $link->target_account_id)
                : null;
        });
    }

    public static function activeKey(int $sourcePlaylistId, StreamingProvider $provider, string $targetAccountId): string
    {
        return hash('sha256', implode('|', [$sourcePlaylistId, $provider->value, $targetAccountId]));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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

    /** @return HasMany<ExportOperation, $this> */
    public function exportOperations(): HasMany
    {
        return $this->hasMany(ExportOperation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => StreamingProvider::class,
            'destination_type' => ExportDestinationType::class,
            'retired_at' => 'immutable_datetime',
        ];
    }
}
