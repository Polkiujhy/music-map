<?php

namespace App\Integrations\PlatformAccess;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class YouTubeProbe implements PlatformProbe
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private RefreshTokenRotationSink $rotationSink,
    ) {}

    public function probe(TechnicalConfiguration|TesterSession $input): ProbeResult|ProbeFailure
    {
        $principal = $input instanceof TechnicalConfiguration ? 'technical' : 'tester';

        if ($input->provider !== 'youtube') {
            return ProbeFailure::make('invalid-invocation', null, $principal);
        }

        try {
            $refresh = Http::asForm()
                ->connectTimeout(5)
                ->timeout(10)
                ->post(self::TOKEN_URL, [
                    'client_id' => $input->clientId,
                    'client_secret' => $input->clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $input->refreshToken,
                ]);
        } catch (Throwable) {
            return ProviderFailureMapper::transport('youtube', $principal);
        }

        if (! $refresh->successful()) {
            return ProviderFailureMapper::response(
                'youtube',
                $principal,
                ProviderFailureMapper::STAGE_REFRESH,
                $refresh,
            );
        }

        $refreshPayload = $refresh->json();

        if (! is_array($refreshPayload)
            || ! PlatformAccessProtocol::isSecretString($refreshPayload['access_token'] ?? null)) {
            return ProviderFailureMapper::invalidResponse('youtube', $principal);
        }

        if (! $this->hasExactScopes($refreshPayload['scope'] ?? null)) {
            return ProviderFailureMapper::scopeMismatch('youtube', $principal);
        }

        $replacement = $refreshPayload['refresh_token'] ?? null;

        if ($replacement !== null && ! PlatformAccessProtocol::isSecretString($replacement)) {
            return ProviderFailureMapper::invalidResponse('youtube', $principal);
        }

        $rotationFailure = $this->rotationSink->write('youtube', $principal, $replacement);

        if ($rotationFailure !== null) {
            return ProviderFailureMapper::rotationRequired('youtube', $principal);
        }

        $request = Http::withToken($refreshPayload['access_token'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);

        try {
            $channels = $request->get(self::API_URL.'/channels', [
                'part' => 'id',
                'mine' => 'true',
            ]);
        } catch (Throwable) {
            return ProviderFailureMapper::transport('youtube', $principal);
        }

        if (! $channels->successful()) {
            return ProviderFailureMapper::response(
                'youtube',
                $principal,
                ProviderFailureMapper::STAGE_IDENTITY,
                $channels,
            );
        }

        $accountId = $this->singleChannelId($channels);

        if ($accountId === null) {
            return ProviderFailureMapper::invalidResponse('youtube', $principal);
        }

        if ($accountId !== $input->expectedAccountId) {
            return ProviderFailureMapper::accountMismatch('youtube', $principal);
        }

        if ($input instanceof TechnicalConfiguration) {
            return ProbeResult::success('youtube', 'technical');
        }

        $fixtureFailure = $this->validateFixture($request, $input);

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

        $expected = PlatformAccessProtocol::scopesFor('youtube');
        sort($expected, SORT_STRING);

        return $scopes === $expected;
    }

    private function singleChannelId(Response $response): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)
            || ! isset($payload['items'])
            || ! is_array($payload['items'])
            || count($payload['items']) !== 1
            || ! PlatformAccessProtocol::isBoundedString($payload['items'][0]['id'] ?? null, 255)) {
            return null;
        }

        return $payload['items'][0]['id'];
    }

    private function validateFixture(PendingRequest $request, TesterSession $session): ?ProbeFailure
    {
        try {
            $playlist = $request->get(self::API_URL.'/playlists', [
                'part' => 'snippet,status',
                'id' => $session->playlistId,
            ]);

            if (! $playlist->successful()) {
                return ProviderFailureMapper::response(
                    'youtube',
                    'tester',
                    ProviderFailureMapper::STAGE_FIXTURE,
                    $playlist,
                );
            }

            $payload = $playlist->json();

            if (! is_array($payload)
                || ! isset($payload['items'])
                || ! is_array($payload['items'])
                || ! array_is_list($payload['items'])) {
                return ProviderFailureMapper::invalidResponse('youtube', 'tester');
            }

            if (count($payload['items']) !== 1) {
                return ProviderFailureMapper::fixtureInvalid('youtube', 'tester');
            }

            if (! is_array($payload['items'][0])
                || ! PlatformAccessProtocol::isBoundedString($payload['items'][0]['snippet']['channelId'] ?? null, 255)
                || ! is_string($payload['items'][0]['status']['privacyStatus'] ?? null)) {
                return ProviderFailureMapper::invalidResponse('youtube', 'tester');
            }

            if ($payload['items'][0]['snippet']['channelId'] !== $session->expectedAccountId
                || $payload['items'][0]['status']['privacyStatus'] !== 'private') {
                return ProviderFailureMapper::fixtureInvalid('youtube', 'tester');
            }

            $items = $request->get(self::API_URL.'/playlistItems', [
                'part' => 'id',
                'playlistId' => $session->playlistId,
                'maxResults' => 1,
            ]);

            if (! $items->successful()) {
                return ProviderFailureMapper::response(
                    'youtube',
                    'tester',
                    ProviderFailureMapper::STAGE_FIXTURE,
                    $items,
                );
            }

            $itemPayload = $items->json();

            if (! is_array($itemPayload)
                || ! isset($itemPayload['items'])
                || ! is_array($itemPayload['items'])
                || ! array_is_list($itemPayload['items'])) {
                return ProviderFailureMapper::invalidResponse('youtube', 'tester');
            }

            return $itemPayload['items'] === [] && ! array_key_exists('nextPageToken', $itemPayload)
                ? null
                : ProviderFailureMapper::fixtureInvalid('youtube', 'tester');
        } catch (Throwable) {
            return ProviderFailureMapper::transport('youtube', 'tester');
        }
    }

    private function mutateAndRestore(PendingRequest $request, TesterSession $session): ProbeResult|ProbeFailure
    {
        $outcome = null;
        $knownItemIds = [];
        $ambiguousMutation = false;

        foreach ($session->itemUris as $videoId) {
            try {
                $insert = $request->post(self::API_URL.'/playlistItems?part=snippet', [
                    'snippet' => [
                        'playlistId' => $session->playlistId,
                        'resourceId' => [
                            'kind' => 'youtube#video',
                            'videoId' => $videoId,
                        ],
                    ],
                ]);

                if (! $insert->successful()) {
                    $outcome = ProviderFailureMapper::response(
                        'youtube',
                        'tester',
                        ProviderFailureMapper::STAGE_INSERT,
                        $insert,
                    );
                    $ambiguousMutation = $insert->serverError();
                    break;
                }

                $itemId = $insert->json('id');

                if (! PlatformAccessProtocol::isBoundedString($itemId, 255)) {
                    $outcome = ProviderFailureMapper::invalidResponse('youtube', 'tester');
                    $ambiguousMutation = true;
                    break;
                }

                $knownItemIds[] = $itemId;
            } catch (Throwable) {
                $outcome = ProviderFailureMapper::transport('youtube', 'tester');
                $ambiguousMutation = true;
                break;
            }
        }

        if ($outcome === null) {
            try {
                $verification = $this->listItems($request, $session);

                if (! $verification->successful()) {
                    $outcome = ProviderFailureMapper::response(
                        'youtube',
                        'tester',
                        ProviderFailureMapper::STAGE_FIXTURE,
                        $verification,
                    );
                } else {
                    $listed = $this->youtubeItems($verification);
                    $actualVideoIds = $listed === null
                        ? null
                        : array_column($listed['items'], 'video_id');

                    $outcome = $listed !== null
                        && $listed['has_next_page'] === false
                        && $actualVideoIds === $session->itemUris
                        ? ProbeResult::success('youtube', 'tester')
                        : ProviderFailureMapper::invalidResponse('youtube', 'tester');
                }
            } catch (Throwable) {
                $outcome = ProviderFailureMapper::transport('youtube', 'tester');
            }
        }

        $cleanupFailed = $this->cleanup($request, $session, $knownItemIds);

        if ($ambiguousMutation || $cleanupFailed) {
            return ProviderFailureMapper::cleanupFailed('youtube', 'tester');
        }

        return $outcome ?? ProviderFailureMapper::invalidResponse('youtube', 'tester');
    }

    private function cleanup(
        PendingRequest $request,
        TesterSession $session,
        array $knownItemIds,
    ): bool {
        $failed = false;
        $itemIds = [];

        try {
            $current = $this->listItems($request, $session);

            if (! $current->successful()) {
                $failed = true;
                $itemIds = $knownItemIds;
            } else {
                $listed = $this->youtubeItems($current);

                if ($listed === null) {
                    $failed = true;
                    $itemIds = $knownItemIds;
                } else {
                    $failed = $listed['has_next_page'];
                    $itemIds = array_values(array_unique([
                        ...$knownItemIds,
                        ...array_column($listed['items'], 'id'),
                    ]));
                }
            }
        } catch (Throwable) {
            $failed = true;
            $itemIds = $knownItemIds;
        }

        foreach (array_values(array_unique($itemIds)) as $itemId) {
            try {
                $delete = $request->delete(
                    self::API_URL.'/playlistItems?id='.rawurlencode($itemId),
                );

                if (! $delete->successful()) {
                    $failed = true;
                }
            } catch (Throwable) {
                $failed = true;
            }
        }

        try {
            $verification = $this->listItems($request, $session);
            $listed = $verification->successful() ? $this->youtubeItems($verification) : null;

            if ($listed === null || $listed['has_next_page'] || $listed['items'] !== []) {
                $failed = true;
            }
        } catch (Throwable) {
            $failed = true;
        }

        return $failed;
    }

    private function listItems(PendingRequest $request, TesterSession $session): Response
    {
        return $request->get(self::API_URL.'/playlistItems', [
            'part' => 'id,snippet',
            'playlistId' => $session->playlistId,
            'maxResults' => 50,
        ]);
    }

    private function youtubeItems(Response $response): ?array
    {
        $payload = $response->json();

        if (! is_array($payload)
            || ! isset($payload['items'])
            || ! is_array($payload['items'])
            || ! array_is_list($payload['items'])) {
            return null;
        }

        $items = [];

        foreach ($payload['items'] as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $videoId = is_array($item) ? ($item['snippet']['resourceId']['videoId'] ?? null) : null;

            if (! PlatformAccessProtocol::isBoundedString($id, 255)
                || ! is_string($videoId)) {
                return null;
            }

            $items[] = ['id' => $id, 'video_id' => $videoId];
        }

        return [
            'items' => $items,
            'has_next_page' => array_key_exists('nextPageToken', $payload),
        ];
    }
}
