<?php

namespace App\Models;

use Database\Factories\AuthIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'provider_user_id'])]
class AuthIdentity extends Model
{
    /** @use HasFactory<AuthIdentityFactory> */
    use HasFactory;

    /**
     * Get the user that owns this authentication identity.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
