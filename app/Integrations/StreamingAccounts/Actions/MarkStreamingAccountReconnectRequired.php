<?php

namespace App\Integrations\StreamingAccounts\Actions;

use App\Models\StreamingAccount;

final class MarkStreamingAccountReconnectRequired
{
    public function handle(StreamingAccount $account, int $credentialVersion): bool
    {
        return StreamingAccount::query()
            ->whereKey($account->getKey())
            ->where('user_id', $account->user_id)
            ->where('provider', $account->provider->value)
            ->where('provider_account_id', $account->provider_account_id)
            ->where('credential_version', $credentialVersion)
            ->whereNotNull('refresh_token')
            ->update([
                'refresh_token' => null,
                'credential_version' => $credentialVersion + 1,
                'updated_at' => now(),
            ]) === 1;
    }
}
