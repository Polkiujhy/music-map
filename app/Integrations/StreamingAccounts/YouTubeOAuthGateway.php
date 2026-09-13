<?php

namespace App\Integrations\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class YouTubeOAuthGateway implements StreamingOAuthGateway
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const IDENTITY_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function authorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'access_type' => 'offline',
            'client_id' => $this->configuration('client_id'),
            'prompt' => 'consent',
            'redirect_uri' => $this->configuration('redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', StreamingProvider::YouTube->requiredScopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure
    {
        if ($code === '') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        return $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->configuration('redirect_uri'),
        ], false);
    }

    public function refresh(string $refreshToken): StreamingGrant|StreamingOAuthFailure
    {
        if ($refreshToken === '') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        return $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], true);
    }

    public function identity(string $accessToken): StreamingIdentity|StreamingOAuthFailure
    {
        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(self::IDENTITY_URL, [
                    'mine' => 'true',
                    'part' => 'id,snippet',
                ]);
        } catch (Throwable) {
            return StreamingOAuthFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return $this->responseFailure($response, false);
        }

        $items = $response->json('items');

        if (! is_array($items) || count($items) !== 1) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        $accountId = $items[0]['id'] ?? null;
        $label = $items[0]['snippet']['title'] ?? null;

        if (! $this->bounded($accountId, 255)
            || ($label !== null && ! $this->bounded($label, 255))) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        return new StreamingIdentity($accountId, $label);
    }

    public function revoke(string $token): ?StreamingOAuthFailure
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::REVOKE_URL, ['token' => $token]);
        } catch (Throwable) {
            return StreamingOAuthFailure::TemporarilyUnavailable;
        }

        return $response->successful() ? null : $this->responseFailure($response, false);
    }

    private function tokenRequest(array $data, bool $refresh): StreamingGrant|StreamingOAuthFailure
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->configuration('client_id'),
                    'client_secret' => $this->configuration('client_secret'),
                    ...$data,
                ]);
        } catch (Throwable) {
            return StreamingOAuthFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return $this->responseFailure($response, $refresh);
        }

        $accessToken = $response->json('access_token');
        $replacement = $response->json('refresh_token');
        $scope = $response->json('scope');

        if (! $this->secret($accessToken)
            || ($replacement !== null && ! $this->secret($replacement))
            || (! $refresh && ! $this->secret($replacement))
            || ($scope !== null && ! is_string($scope))) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        return new StreamingGrant(
            $accessToken,
            $replacement,
            is_string($scope) ? $this->scopes($scope) : [],
        );
    }

    private function responseFailure(Response $response, bool $refresh): StreamingOAuthFailure
    {
        $error = $response->json('error');
        $reason = $response->json('error.errors.0.reason');

        if ($refresh && $error === 'invalid_grant') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            return StreamingOAuthFailure::QuotaExceeded;
        }

        if ($response->status() === 429
            || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return StreamingOAuthFailure::RateLimited;
        }

        return StreamingOAuthFailure::TemporarilyUnavailable;
    }

    private function configuration(string $key): string
    {
        return (string) config("services.streaming_accounts.youtube.{$key}");
    }

    /** @return list<string> */
    private function scopes(string $scope): array
    {
        $scopes = preg_split('/ +/', trim($scope)) ?: [];
        $scopes = array_values(array_unique(array_filter($scopes)));
        sort($scopes, SORT_STRING);

        return $scopes;
    }

    private function secret(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 8192;
    }

    private function bounded(mixed $value, int $limit): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $limit;
    }
}
