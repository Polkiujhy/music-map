<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the external authentication identities for the user.
     *
     * @return HasMany<AuthIdentity, $this>
     */
    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    /**
     * Get the streaming accounts connected by the user.
     *
     * @return HasMany<StreamingAccount, $this>
     */
    public function streamingAccounts(): HasMany
    {
        return $this->hasMany(StreamingAccount::class);
    }

    /**
     * Get the playlists in the user's private bank.
     *
     * @return HasMany<Playlist, $this>
     */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    /** @return HasMany<ExportReview, $this> */
    public function exportReviews(): HasMany
    {
        return $this->hasMany(ExportReview::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
