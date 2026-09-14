<?php

namespace App\Integrations\PlaylistImport;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistReference;

final class PlaylistShareUrlParser
{
    private const MAX_URL_BYTES = 512;

    public function parse(string $url): PlaylistReference|ImportFailureCode
    {
        if ($url === ''
            || strlen($url) > self::MAX_URL_BYTES
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|20|7f|2f|5c|3f|23|26|3d)/i', $url) === 1) {
            return ImportFailureCode::InvalidUrl;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
            || ! isset($parts['host'], $parts['path'])) {
            return ImportFailureCode::InvalidUrl;
        }

        $query = $this->queryParameters($parts['query'] ?? '');

        if ($query === null) {
            return ImportFailureCode::InvalidUrl;
        }

        return match ($parts['host']) {
            'open.spotify.com' => $this->spotify($parts['path'], $query),
            'www.youtube.com', 'music.youtube.com' => $this->youtube($parts['path'], $query),
            default => ImportFailureCode::UnsupportedProvider,
        };
    }

    /**
     * @param  array<string, string>  $query
     */
    private function spotify(string $path, array $query): PlaylistReference|ImportFailureCode
    {
        if (array_diff(array_keys($query), ['si']) !== []
            || preg_match('#^/playlist/([A-Za-z0-9]{22})$#D', $path, $matches) !== 1) {
            return ImportFailureCode::InvalidUrl;
        }

        $id = $matches[1];

        return new PlaylistReference(
            StreamingProvider::Spotify,
            $id,
            "https://open.spotify.com/playlist/{$id}",
        );
    }

    /**
     * @param  array<string, string>  $query
     */
    private function youtube(string $path, array $query): PlaylistReference|ImportFailureCode
    {
        if ($path !== '/playlist'
            || array_diff(array_keys($query), ['list', 'si']) !== []
            || ! isset($query['list'])
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $query['list']) !== 1) {
            return ImportFailureCode::InvalidUrl;
        }

        $id = $query['list'];

        return new PlaylistReference(
            StreamingProvider::YouTube,
            $id,
            'https://www.youtube.com/playlist?list='.$id,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function queryParameters(string $query): ?array
    {
        if ($query === '') {
            return [];
        }

        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '' || substr_count($pair, '=') !== 1) {
                return null;
            }

            [$name, $value] = explode('=', $pair, 2);

            if ($name === ''
                || $value === ''
                || isset($parameters[$name])
                || rawurlencode(rawurldecode($name)) !== $name
                || rawurlencode(rawurldecode($value)) !== $value) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }
}
