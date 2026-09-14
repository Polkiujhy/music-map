<?php

namespace App\Integrations\StreamingAccounts\Actions;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class LinkStreamingAccount
{
    public function handle(
        User $user,
        StreamingProvider $provider,
        StreamingGrant $grant,
        StreamingIdentity $identity,
    ): StreamingAccount|StreamingOAuthFailure {
        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        if (! $this->containsRequiredScopes($provider, $grant->scopes)) {
            return StreamingOAuthFailure::MissingScope;
        }

        if ($identity->accountId === '' || strlen($identity->accountId) > 255) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        try {
            return $this->linkAttempt($user, $provider, $grant, $identity);
        } catch (UniqueConstraintViolationException) {
            try {
                return $this->linkAttempt($user, $provider, $grant, $identity);
            } catch (UniqueConstraintViolationException) {
                return StreamingOAuthFailure::AccessUnavailable;
            }
        }
    }

    protected function linkAttempt(
        User $user,
        StreamingProvider $provider,
        StreamingGrant $grant,
        StreamingIdentity $identity,
    ): StreamingAccount|StreamingOAuthFailure {
        return DB::transaction(function () use ($user, $provider, $grant, $identity) {
            $userAccount = StreamingAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $provider->value)
                ->lockForUpdate()
                ->first();

            $providerAccount = StreamingAccount::query()
                ->where('provider', $provider->value)
                ->where('provider_account_id', $identity->accountId)
                ->lockForUpdate()
                ->first();

            if ($providerAccount !== null && (int) $providerAccount->user_id !== (int) $user->getKey()) {
                return StreamingOAuthFailure::AccessUnavailable;
            }

            if ($userAccount !== null && $userAccount->provider_account_id !== $identity->accountId) {
                return StreamingOAuthFailure::AccountConflict;
            }

            $account = $userAccount ?? $providerAccount ?? new StreamingAccount;
            $isNew = ! $account->exists;
            $credentialsChanged = $isNew
                || $account->refresh_token === null
                || ! hash_equals((string) $account->refresh_token, $grant->refreshToken);

            if ($isNew) {
                $account->user()->associate($user);
                $account->provider = $provider;
                $account->provider_account_id = $identity->accountId;
                $account->credential_version = 1;
            } elseif ($credentialsChanged) {
                $account->credential_version++;
            }

            $account->label = $identity->label;
            $account->market = $provider === StreamingProvider::Spotify && $identity->market !== null
                ? strtoupper($identity->market)
                : null;
            $account->scopes = $grant->scopes;
            $account->refresh_token = $grant->refreshToken;
            $account->reauthorization_due_at = null;
            $account->save();

            return $account;
        });
    }

    /** @param list<string> $actual */
    private function containsRequiredScopes(StreamingProvider $provider, array $actual): bool
    {
        $actual = array_values(array_unique($actual));

        return array_diff($provider->requiredScopes(), $actual) === [];
    }
}
