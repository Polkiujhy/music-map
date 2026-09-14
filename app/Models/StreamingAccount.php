<?php

namespace App\Models;

use App\Enums\StreamingProvider;
use Database\Factories\StreamingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider',
    'provider_account_id',
    'label',
    'market',
    'scopes',
    'reauthorization_due_at',
    'credential_version',
])]
#[Hidden(['refresh_token'])]
class StreamingAccount extends Model
{
    /** @use HasFactory<StreamingAccountFactory> */
    use HasFactory;

    public const STATE_CONNECTED = 'connected';

    public const STATE_RECONNECT_REQUIRED = 'reconnect-required';

    /**
     * Get the user that owns this streaming account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ExportReview, $this> */
    public function exportReviews(): HasMany
    {
        return $this->hasMany(ExportReview::class);
    }

    /** @return HasMany<PlaylistSynchronization, $this> */
    public function playlistSynchronizations(): HasMany
    {
        return $this->hasMany(PlaylistSynchronization::class);
    }

    public function connectionState(): string
    {
        return $this->refresh_token === null
            ? self::STATE_RECONNECT_REQUIRED
            : self::STATE_CONNECTED;
    }

    /**
     * Normalize granted scopes before they are persisted.
     *
     * @param  list<string>  $scopes
     */
    public function setScopesAttribute(array $scopes): void
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes, SORT_STRING);

        $this->attributes['scopes'] = json_encode($scopes, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => StreamingProvider::class,
            'scopes' => 'array',
            'refresh_token' => 'encrypted',
            'reauthorization_due_at' => 'immutable_datetime',
            'credential_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
