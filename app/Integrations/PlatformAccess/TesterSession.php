<?php

namespace App\Integrations\PlatformAccess;

use JsonException;

final readonly class TesterSession
{
    private const KEYS = [
        'client_id',
        'client_secret',
        'expected_account_id',
        'item_uris',
        'playlist_id',
        'principal',
        'protocol',
        'provider',
        'refresh_token',
    ];

    private function __construct(
        public string $provider,
        public string $clientId,
        public string $clientSecret,
        public string $refreshToken,
        public string $expectedAccountId,
        public array $itemUris,
        public string $playlistId,
    ) {}

    public static function fromFile(string $provider, string $path = PlatformAccessProtocol::SESSION_PATH): self|ProbeFailure
    {
        $json = @file_get_contents(
            $path,
            false,
            null,
            0,
            PlatformAccessProtocol::MAX_SESSION_DOCUMENT_BYTES + 1,
        );

        if (! is_string($json) || strlen($json) > PlatformAccessProtocol::MAX_SESSION_DOCUMENT_BYTES) {
            return ProbeFailure::make('invalid-session', $provider, 'tester');
        }

        return self::fromJson($provider, $json);
    }

    public static function fromJson(string $provider, string $json): self|ProbeFailure
    {
        if (! PlatformAccessProtocol::isProvider($provider)) {
            return ProbeFailure::make('invalid-invocation', null, 'tester');
        }

        try {
            $values = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ProbeFailure::make('invalid-session', $provider, 'tester');
        }

        if (! is_array($values) || array_is_list($values)) {
            return ProbeFailure::make('invalid-session', $provider, 'tester');
        }

        $keys = array_keys($values);
        sort($keys, SORT_STRING);

        if ($keys !== self::KEYS
            || ($values['protocol'] ?? null) !== PlatformAccessProtocol::VERSION
            || ($values['provider'] ?? null) !== $provider
            || ($values['principal'] ?? null) !== 'tester'
            || ! PlatformAccessProtocol::isSecretString($values['client_id'] ?? null)
            || ! PlatformAccessProtocol::isSecretString($values['client_secret'] ?? null)
            || ! PlatformAccessProtocol::isSecretString($values['refresh_token'] ?? null)
            || ! PlatformAccessProtocol::isSecretString($values['expected_account_id'] ?? null, 255)
            || ! PlatformAccessProtocol::isSecretString($values['playlist_id'] ?? null, 255)
            || ! self::validItems($provider, $values['item_uris'] ?? null)) {
            return ProbeFailure::make('invalid-session', $provider, 'tester');
        }

        return new self(
            $provider,
            $values['client_id'],
            $values['client_secret'],
            $values['refresh_token'],
            $values['expected_account_id'],
            $values['item_uris'],
            $values['playlist_id'],
        );
    }

    private static function validItems(string $provider, mixed $items): bool
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) !== 3) {
            return false;
        }

        $pattern = $provider === 'spotify'
            ? '/^spotify:track:[A-Za-z0-9]{22}$/'
            : '/^[A-Za-z0-9_-]{11}$/';

        foreach ($items as $item) {
            if (! PlatformAccessProtocol::isSecretString($item, 255)
                || preg_match($pattern, $item) !== 1) {
                return false;
            }
        }

        return true;
    }
}
