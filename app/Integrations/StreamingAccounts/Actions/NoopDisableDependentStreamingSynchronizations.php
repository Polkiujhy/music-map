<?php

namespace App\Integrations\StreamingAccounts\Actions;

use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Models\StreamingAccount;

final class NoopDisableDependentStreamingSynchronizations implements DisableDependentStreamingSynchronizations
{
    public function handle(StreamingAccount $account): void
    {
        // S-04 has no dependent synchronization records. S-08 replaces this binding.
    }
}
