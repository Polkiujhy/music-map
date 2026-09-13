<?php

namespace App\Integrations\StreamingAccounts\Actions;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DisconnectStreamingAccount
{
    public function __construct(
        private Container $container,
        private DisableDependentStreamingSynchronizations $disableSynchronizations,
    ) {}

    /**
     * Delete an owned account locally and report whether YouTube revocation was confirmed.
     */
    public function handle(User $owner, StreamingAccount $account): bool
    {
        [$provider, $refreshToken] = DB::transaction(function () use ($owner, $account): array {
            $lockedAccount = $owner->streamingAccounts()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedAccount instanceof StreamingAccount) {
                throw (new ModelNotFoundException)->setModel(StreamingAccount::class, [$account->getKey()]);
            }

            $provider = $lockedAccount->provider;
            $refreshToken = $lockedAccount->refresh_token;

            $this->disableSynchronizations->handle($lockedAccount);
            $lockedAccount->delete();

            return [$provider, $refreshToken];
        });

        if ($provider !== StreamingProvider::YouTube
            || ! is_string($refreshToken)
            || $refreshToken === '') {
            return false;
        }

        try {
            return $this->gateway($provider)->revoke($refreshToken) === null;
        } catch (Throwable) {
            return false;
        }
    }

    private function gateway(StreamingProvider $provider): StreamingOAuthGateway
    {
        return $this->container->make('streaming-oauth.'.$provider->value);
    }
}
