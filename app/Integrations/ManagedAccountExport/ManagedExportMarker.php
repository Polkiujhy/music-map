<?php

namespace App\Integrations\ManagedAccountExport;

use InvalidArgumentException;

final class ManagedExportMarker
{
    public const PREFIX = 'music-map-managed-export:v1:';

    public static function generate(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function line(string $marker): string
    {
        if (! self::isValid($marker)) {
            throw new InvalidArgumentException('Managed export markers must contain exactly 128 random bits as ASCII UUID text.');
        }

        return self::PREFIX.$marker;
    }

    public static function isValid(string $marker): bool
    {
        return preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $marker) === 1;
    }

    public static function appearsExactlyOnce(string $description, string $marker): bool
    {
        $line = self::line($marker);

        return substr_count($description, self::PREFIX) === 1
            && str_ends_with($description, $line)
            && preg_match('/(?:^|\R)'.preg_quote($line, '/').'$/u', $description) === 1;
    }
}
