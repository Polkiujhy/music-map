<?php

namespace App\Integrations\PlatformAccess;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class SpotifyProbe implements PlatformProbe
{
    private const TOKEN_URL = 'https://accounts.spotify.com/api/token';

    private const API_URL = 'https://api.spotify.com/v1';

    public function __construct(
        private RefreshTokenRotationSink $rotationSink,
    ) {}

    public function probe(TechnicalConfiguration|TesterSession $input): ProbeResult|ProbeFailure
    {
        $principal = $input instanceof TechnicalConfiguration ? 'technical' : 'tester';

        if ($input->provider !== 'spotify') {
            return ProbeFailure::make('invalid-invocation', null, $principal);
        }

        try {
            $refresh = Http::asForm()
                ->withBasicAuth($input->clientId, $input->clientSecret)
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $input->refreshToken,
                ]);
        } catch (Throwable) {
            return ProviderFailureMapper::transport('spotify', $principal);
        }

        if (! $refresh->successful()) {
            return ProviderFailureMapper::response(
                'spotify',
                $principal,
                ProviderFailureMapper::STAGE_REFRESH,
                $refresh,
            );
        }

        $refreshPayload = $refresh->json();

        if (! is_array($refreshPayload)
            || ! PlatformAccessProtocol::isSecretString($refreshPayload['access_token'] ?? null)) {
            return ProviderFailureMapper::invalidResponse('spotify', $principal);
        }

        if (! $this->hasExactScopes($refreshPayload['scope'] ?? null)) {
            return ProviderFailureMapper::scopeMismatch('spotify', $principal);
        }

        $replacement = $refreshPayload['refresh_token'] ?? null;

        if ($replacement !== null && ! PlatformAccessProtocol::isSecretString($replacement)) {
            return ProviderFailureMapper::invalidResponse('spotify', $principal);
        }

        $rotationFailure = $this->rotationSink->write('spotify', $principal, $replacement);

        if ($rotationFailure !== null) {
            return ProviderFailureMapper::rotationRequired('spotify', $principal);
        }

        $request = Http::withToken($refreshPayload['access_token'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);

        try {
            $profile = $request->get(self::API_URL.'/me');
        } catch (Throwable) {
            return ProviderFailureMapper::transport('spotify', $principal);
        }

        if (! $profile->successful()) {
            return ProviderFailureMapper::response(
                'spotify',
                $principal,
                ProviderFailureMapper::STAGE_IDENTITY,
                $profile,
            );
        }

        $profilePayload = $profile->json();

        if (! is_array($profilePayload)
            || ! PlatformAccessProtocol::isBoundedString($profilePayload['account_id'] ?? null, 255)) {
            return ProviderFailureMapper::invalidResponse('spotify', $principal);
        }

        if ($profilePayload['account_id'] !== $input->expectedAccountId) {
            return ProviderFailureMapper::accountMismatch('spotify', $principal);
        }

        if ($input instanceof TechnicalConfiguration) {
            return ProbeResult::success('spotify', 'technical');
        }

        if (! PlatformAccessProtocol::isBoundedString($profilePayload['id'] ?? null, 255)) {
            return ProviderFailureMapper::fixtureInvalid('spotify', 'tester');
        }

        $fixtureFailure = $this->validateFixture($request, $input, $profilePayload['id']);

        if ($fixtureFailure !== null) {
            return $fixtureFailure;
        }

        return $this->mutateAndRestore($request, $input);
    }

    private function hasExactScopes(mixed $scope): bool
    {
        if (! is_string($scope)) {
            return false;
        }

        $scopes = preg_split('/ +/', trim($scope)) ?: [];
        $scopes = array_values(array_unique(array_filter($scopes, static fn (string $value): bool => $value !== '')));
        sort($scopes, SORT_STRING);

        $expected = PlatformAccessProtocol::scopesFor('spotify');
        sort($expected, SORT_STRING);

        return $scopes === $expected;
    }

    private function validateFixture(
        PendingRequest $request,
        TesterSession $session,
        string $profileId,
    ): ?ProbeFailure {
        try {
            $playlist = $request->get($this->playlistUrl($session));

            if (! $playlist->successful()) {
                return ProviderFailureMapper::response(
                    'spotify',
                    'tester',
                    ProviderFailureMapper::STAGE_FIXTURE,
                    $playlist,
                );
            }

            $payload = $playlist->json();

            if (! is_array($payload) || ! is_bool($payload['public'] ?? null)) {
                return ProviderFailureMapper::invalidResponse('spotify', 'tester');
            }

            if (! PlatformAccessProtocol::isBoundedString($payload['owner']['id'] ?? null, 255)
                || $payload['public'] !== false
                || $payload['owner']['id'] !== $profileId) {
                return ProviderFailureMapper::fixtureInvalid('spotify', 'tester');
            }

            $items = $request->get($this->itemsUrl($session), ['limit' => 1]);

            if (! $items->successful()) {
                return ProviderFailureMapper::response(
                    'spotify',
                    'tester',
                    ProviderFailureMapper::STAGE_FIXTURE,
                    $items,
                );
            }

            $itemUris = $this->spotifyItemUris($items);

            if ($itemUris === null) {
                return ProviderFailureMapper::invalidResponse('spotify', 'tester');
            }

            return $itemUris === []
                ? null
                : ProviderFailureMapper::fixtureInvalid('spotify', 'tester');
        } catch (Throwable) {
            return ProviderFailureMapper::transport('spotify', 'tester');
        }
    }

    private function mutateAndRestore(PendingRequest $request, TesterSession $session): ProbeResult|ProbeFailure
    {
        $outcome = null;

        try {
            $replace = $request->put($this->itemsUrl($session), ['uris' => $session->itemUris]);

            if (! $replace->successful()) {
                $outcome = ProviderFailureMapper::response(
                    'spotify',
                    'tester',
                    ProviderFailureMapper::STAGE_INSERT,
                    $replace,
                );
            } else {
                $verification = $request->get($this->itemsUrl($session), ['limit' => 50]);

                if (! $verification->successful()) {
                    $outcome = ProviderFailureMapper::response(
                        'spotify',
                        'tester',
                        ProviderFailureMapper::STAGE_FIXTURE,
                        $verification,
                    );
                } else {
                    $actualUris = $this->spotifyItemUris($verification);
                    $outcome = $actualUris === $session->itemUris
                        ? ProbeResult::success('spotify', 'tester')
                        : ProviderFailureMapper::invalidResponse('spotify', 'tester');
                }
            }
        } catch (Throwable) {
            $outcome = ProviderFailureMapper::transport('spotify', 'tester');
        }

        $cleanupFailure = $this->cleanup($request, $session);

        return $cleanupFailure ?? $outcome ?? ProviderFailureMapper::invalidResponse('spotify', 'tester');
    }

    private function cleanup(PendingRequest $request, TesterSession $session): ?ProbeFailure
    {
        try {
            $replace = $request->put($this->itemsUrl($session), ['uris' => []]);

            if (! $replace->successful()) {
                return ProviderFailureMapper::cleanupFailed('spotify', 'tester');
            }

            $verification = $request->get($this->itemsUrl($session), ['limit' => 1]);

            if (! $verification->successful() || $this->spotifyItemUris($verification) !== []) {
                return ProviderFailureMapper::cleanupFailed('spotify', 'tester');
            }

            return null;
        } catch (Throwable) {
            return ProviderFailureMapper::cleanupFailed('spotify', 'tester');
        }
    }

    private function spotifyItemUris(Response $response): ?array
    {
        $payload = $response->json();

        if (! is_array($payload)
            || ! isset($payload['items'])
            || ! is_array($payload['items'])
            || ! array_is_list($payload['items'])
            || (isset($payload['next']) && $payload['next'] !== null)) {
            return null;
        }

        $uris = [];

        foreach ($payload['items'] as $item) {
            $uri = is_array($item) ? ($item['item']['uri'] ?? null) : null;

            if (! is_string($uri)) {
                return null;
            }

            $uris[] = $uri;
        }

        return $uris;
    }

    private function playlistUrl(TesterSession $session): string
    {
        return self::API_URL.'/playlists/'.rawurlencode($session->playlistId);
    }

    private function itemsUrl(TesterSession $session): string
    {
        return $this->playlistUrl($session).'/items';
    }
}
