<?php

namespace App\Integrations\ExportMatching;

final class MatchNormalizer
{
    public static function text(?string $value): string
    {
        $value = mb_strtolower(trim($value ?? ''), 'UTF-8');
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @param list<string> $values @return list<string> */
    public static function names(array $values): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(self::text(...), $values))));
        sort($normalized);

        return $normalized;
    }

    public static function hasVariantMarker(string $value): bool
    {
        return preg_match('/\b(cover|remix|live|karaoke|tribute|instrumental|sped up|slowed)\b/u', self::text($value)) === 1;
    }
}
