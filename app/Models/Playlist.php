<?php

namespace App\Models;

use App\Enums\StreamingProvider;
use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'source_provider',
    'source_playlist_id',
    'source_account_id',
    'canonical_source_url',
    'provider_revision',
    'name',
    'description',
    'provider_metadata_refreshed_at',
    'imported_at',
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
     * @return HasMany<PlaylistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlaylistItem::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_provider' => StreamingProvider::class,
            'provider_metadata_refreshed_at' => 'immutable_datetime',
            'imported_at' => 'immutable_datetime',
        ];
    }
}
