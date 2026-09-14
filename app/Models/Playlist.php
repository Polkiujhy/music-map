<?php

namespace App\Models;

use App\Enums\PlaylistOrigin;
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
    'origin',
    'source_provider',
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

    /** @return HasMany<PlaylistExport, $this> */
    public function managedExports(): HasMany
    {
        return $this->hasMany(PlaylistExport::class, 'source_playlist_id');
    }

    /** @return HasOne<PlaylistExport, $this> */
    public function managedExportTarget(): HasOne
    {
        return $this->hasOne(PlaylistExport::class, 'target_playlist_id');
    }

    public function isManagedTarget(): bool
    {
        return $this->origin === PlaylistOrigin::ManagedTarget;
    }

    public function isExportTarget(): bool
    {
        return $this->isManagedTarget();
    }

    public function assertSource(): void
    {
        abort_if($this->isExportTarget(), 404);
    }

    public function scopeSourceOnly(Builder $query): Builder
    {
        return $query->where('origin', PlaylistOrigin::Imported->value);
    }

    /** @return HasOne<PlaylistSynchronization, $this> */
    public function synchronization(): HasOne
    {
        return $this->hasOne(PlaylistSynchronization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => PlaylistOrigin::class,
            'source_provider' => StreamingProvider::class,
            'provider_metadata_refreshed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
            'bank_content_edited_at' => 'immutable_datetime',
        ];
    }
}
