<?php

namespace App\Integrations\StreamingAccounts\Contracts;

use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;

interface WithStreamingAccess
{
    /**
     * @param  list<string>  $requiredScopes
     * @param  Closure(StreamingAccessContext): (void|StreamingAccessFailure)  $callback
     */
    public function handle(
        User $owner,
        StreamingAccount $account,
        array $requiredScopes,
        Closure $callback,
    ): StreamingAccessResult;
}
