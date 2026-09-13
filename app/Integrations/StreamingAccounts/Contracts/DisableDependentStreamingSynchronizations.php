<?php

namespace App\Integrations\StreamingAccounts\Contracts;

use App\Models\StreamingAccount;

interface DisableDependentStreamingSynchronizations
{
    /**
     * Disable synchronizations while the supplied account is locked for unlink.
     */
    public function handle(StreamingAccount $account): void;
}
