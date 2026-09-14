<?php

namespace App\Integrations\ExportMatching;

use Illuminate\Support\Facades\Http;
use Throwable;

final class SpotifyClientCredentials
{
    private ?string $accessToken = null;

    public function token(): string|MatchingFailure
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $clientId = config('services.export_matching.spotify.client_id');
        $clientSecret = config('services.export_matching.spotify.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            return MatchingFailure::TemporarilyUnavailable;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post('https://accounts.spotify.com/api/token', ['grant_type' => 'client_credentials']);
        } catch (Throwable) {
            return MatchingFailure::TemporarilyUnavailable;
        }

        if (! $response->successful()) {
            return match ($response->status()) {
                401 => MatchingFailure::Unauthorized,
                403 => MatchingFailure::Forbidden,
                429 => MatchingFailure::RateLimited,
                default => $response->serverError()
                    ? MatchingFailure::TemporarilyUnavailable
                    : MatchingFailure::InvalidResponse,
            };
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return MatchingFailure::InvalidResponse;
        }

        return $this->accessToken = $token;
    }
}
