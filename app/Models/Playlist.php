<?php

namespace App\Models;

use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source_provider',
    'role',
    'streaming_account_id',
    'source_playlist_id',
    'source_account_id',
    'canonical_source_url',
    'provider_revision',
    'name',
    'description',
    'provider_metadata_refreshed_at',
    'imported_at',
    'bank_content_edited_at',
])]
class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The optional account used to read this source. Unlinking keeps the bank snapshot.
     *
     * @return BelongsTo<StreamingAccount, $this>
     */
    public function streamingAccount(): BelongsTo
    {
        return $this->belongsTo(StreamingAccount::class);
    }

    /**
     * @return HasMany<PlaylistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }

    /** @return HasMany<ExportReview, $this> */
    public function exportReviews(): HasMany
    {
        return $this->hasMany(ExportReview::class);
    }

    /** @return HasMany<PlaylistExportLink, $this> */
    public function exportLinks(): HasMany
    {
        return $this->hasMany(PlaylistExportLink::class, 'source_playlist_id');
    }

    /** @return HasOne<PlaylistExportLink, $this> */
    public function exportTargetLink(): HasOne
    {
        return $this->hasOne(PlaylistExportLink::class, 'target_playlist_id');
    }

    /** @return HasMany<ExportOperation, $this> */
    public function exportOperations(): HasMany
    {
        return $this->hasMany(ExportOperation::class, 'source_playlist_id');
    }

    /** @return HasMany<ExportOperation, $this> */
    public function activeExportOperations(): HasMany
    {
        return $this->hasMany(ExportOperation::class, 'source_playlist_id')
            ->whereNotNull('active_key')
            ->latest('id');
    }

    /** @param Builder<Playlist> $query */
    public function scopeSourceOnly(Builder $query): void
    {
        $query->where('role', PlaylistRole::Source->value);
    }

    public function assertSource(): self
    {
        if ($this->role !== PlaylistRole::Source) {
            abort(404);
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_provider' => StreamingProvider::class,
            'role' => PlaylistRole::class,
            'provider_metadata_refreshed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'bank_content_edited_at' => 'immutable_datetime',
        ];
    }
}
