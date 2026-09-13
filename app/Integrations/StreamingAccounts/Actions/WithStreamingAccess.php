<?php

namespace App\Integrations\StreamingAccounts\Actions;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess as WithStreamingAccessContract;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final readonly class WithStreamingAccess implements WithStreamingAccessContract
{
    public function __construct(
        private Container $container,
        private MarkStreamingAccountReconnectRequired $markReconnectRequired,
    ) {}

    public function handle(
        User $user,
        StreamingAccount $account,
        array $requiredScopes,
        Closure $callback,
    ): StreamingAccessResult {
        $snapshot = StreamingAccount::query()
            ->whereKey($account->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($snapshot === null) {
            return StreamingAccessResult::failure(StreamingAccessFailure::StaleCredential);
        }

        if ($snapshot->refresh_token === null) {
            return StreamingAccessResult::failure(StreamingAccessFailure::ReconnectRequired);
        }

        $requiredScopes = $this->normalizeScopes($requiredScopes);

        if (array_diff($requiredScopes, $snapshot->scopes) !== []) {
            return StreamingAccessResult::failure(StreamingAccessFailure::MissingScope);
        }

        $credentialVersion = $snapshot->credential_version;
        $grant = $this->gateway($snapshot->provider)->refresh($snapshot->refresh_token);

        if ($grant instanceof StreamingOAuthFailure) {
            if ($grant === StreamingOAuthFailure::AuthorizationDenied) {
                $marked = $this->markReconnectRequired->handle($snapshot, $credentialVersion);

                return StreamingAccessResult::failure(
                    $marked
                        ? StreamingAccessFailure::ReconnectRequired
                        : StreamingAccessFailure::StaleCredential,
                );
            }

            return StreamingAccessResult::failure($this->mapFailure($grant));
        }

        if ($grant->scopes !== [] && array_diff($requiredScopes, $grant->scopes) !== []) {
            return StreamingAccessResult::failure(StreamingAccessFailure::MissingScope);
        }

        if (! $this->guardCredential($snapshot, $grant, $credentialVersion)) {
            return StreamingAccessResult::failure(StreamingAccessFailure::StaleCredential);
        }

        $context = new StreamingAccessContext(
            $snapshot->provider,
            $snapshot->provider_account_id,
            $grant->accessToken,
        );

        try {
            $value = $callback($context);
        } catch (Throwable) {
            return StreamingAccessResult::failure(StreamingAccessFailure::TemporarilyUnavailable);
        }

        if ($value instanceof StreamingAccessContext) {
            return StreamingAccessResult::failure(StreamingAccessFailure::TemporarilyUnavailable);
        }

        return $value instanceof StreamingAccessFailure
            ? StreamingAccessResult::failure($value)
            : StreamingAccessResult::success($value);
    }

    private function guardCredential(
        StreamingAccount $account,
        StreamingGrant $grant,
        int $credentialVersion,
    ): bool {
        $query = StreamingAccount::query()
            ->whereKey($account->getKey())
            ->where('user_id', $account->user_id)
            ->where('provider', $account->provider->value)
            ->where('provider_account_id', $account->provider_account_id)
            ->where('credential_version', $credentialVersion)
            ->whereNotNull('refresh_token');

        if ($grant->refreshToken === null) {
            return $query->exists();
        }

        return $query->update([
            'refresh_token' => Crypt::encryptString($grant->refreshToken),
            'credential_version' => $credentialVersion + 1,
            'updated_at' => now(),
        ]) === 1;
    }

    private function gateway(StreamingProvider $provider): StreamingOAuthGateway
    {
        return match ($provider) {
            StreamingProvider::Spotify => $this->container->make('streaming-oauth.spotify'),
            StreamingProvider::YouTube => $this->container->make('streaming-oauth.youtube'),
        };
    }

    /** @param list<string> $scopes */
    private function normalizeScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes, 'is_string')));
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    private function mapFailure(StreamingOAuthFailure $failure): StreamingAccessFailure
    {
        return match ($failure) {
            StreamingOAuthFailure::RateLimited => StreamingAccessFailure::RateLimited,
            StreamingOAuthFailure::QuotaExceeded => StreamingAccessFailure::QuotaExceeded,
            default => StreamingAccessFailure::TemporarilyUnavailable,
        };
    }
}
