<?php

namespace App\Integrations\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class SpotifyOAuthGateway implements StreamingOAuthGateway
{
    private const AUTHORIZE_URL = 'https://accounts.spotify.com/authorize';

    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const IDENTITY_URL = 'https://api.spotify.com/v1/me';

    public function authorizationUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->configuration('client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->configuration('redirect_uri'),
            'scope' => implode(' ', StreamingProvider::Spotify->requiredScopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure
    {
        if ($code === '') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
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
                ->get(self::IDENTITY_URL);
        } catch (Throwable) {
            return StreamingOAuthFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return $response->status() === 403
                ? StreamingOAuthFailure::AccessUnavailable
                : $this->responseFailure($response, false);
        }

        $accountId = $response->json('id', $response->json('account_id'));
        $label = $response->json('display_name');
        $market = $response->json('country');

        if (! $this->bounded($accountId, 255)
            || ($label !== null && ! $this->bounded($label, 255))
            || ($market !== null && (! is_string($market) || preg_match('/^[A-Za-z]{2}$/', $market) !== 1))) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        return new StreamingIdentity($accountId, $label, $market === null ? null : strtoupper($market));
    }

    public function revoke(string $token): ?StreamingOAuthFailure
    {
        return null;
    }

    private function tokenRequest(array $data, bool $refresh): StreamingGrant|StreamingOAuthFailure
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($this->configuration('client_id'), $this->configuration('client_secret'))
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::TOKEN_URL, $data);
        } catch (Throwable) {
            return StreamingOAuthFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return $response->status() === 403
                ? StreamingOAuthFailure::AccessUnavailable
                : $this->responseFailure($response, $refresh);
        }

        return $this->grant($response, $refresh);
    }

    private function grant(Response $response, bool $refresh): StreamingGrant|StreamingOAuthFailure
    {
        $accessToken = $response->json('access_token');
        $replacement = $response->json('refresh_token');
        $scope = $response->json('scope');

        if (! $this->secret($accessToken)
            || ($replacement !== null && ! $this->secret($replacement))
            || (! $refresh && ! $this->secret($replacement))
            || ! is_string($scope)) {
            return StreamingOAuthFailure::InvalidResponse;
        }

        $scopes = $this->scopes($scope);

        return new StreamingGrant($accessToken, $replacement, $scopes);
    }

    private function responseFailure(Response $response, bool $refresh): StreamingOAuthFailure
    {
        $error = $response->json('error');

        if ($refresh && $error === 'invalid_grant') {
            return StreamingOAuthFailure::AuthorizationDenied;
        }

        if ($response->status() === 429) {
            return StreamingOAuthFailure::RateLimited;
        }

        return StreamingOAuthFailure::TemporarilyUnavailable;
    }

    private function configuration(string $key): string
    {
        return (string) config("services.streaming_accounts.spotify.{$key}");
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
