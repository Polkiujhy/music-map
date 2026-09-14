<?php

namespace App\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use InvalidArgumentException;

final class ManagedPlaylistMetadataFactory
{
    public const SPOTIFY_TITLE_LIMIT = 100;

    public const SPOTIFY_DESCRIPTION_LIMIT = 300;

    public const YOUTUBE_TITLE_LIMIT = 150;

    public const YOUTUBE_DESCRIPTION_LIMIT = 5000;

    private const TITLE_SUFFIX = ' — Music Map';

    private const MANAGED_NOTICE = 'Kopia zarządzana przez Music Map. Edytuj źródło w Music Map.';

    public function make(
        StreamingProvider $provider,
        string $sourceTitle,
        ?string $sourceDescription,
        string $marker,
    ): ManagedPlaylistMetadata {
        [$titleLimit, $descriptionLimit] = match ($provider) {
            StreamingProvider::Spotify => [self::SPOTIFY_TITLE_LIMIT, self::SPOTIFY_DESCRIPTION_LIMIT],
            StreamingProvider::YouTube => [self::YOUTUBE_TITLE_LIMIT, self::YOUTUBE_DESCRIPTION_LIMIT],
        };

        $sourceTitle = $this->plainText($sourceTitle);
        $title = $this->truncate($sourceTitle, $titleLimit - mb_strlen(self::TITLE_SUFFIX)).self::TITLE_SUFFIX;

        $markerLine = ManagedExportMarker::line($marker);
        $suffix = self::MANAGED_NOTICE."\n".$markerLine;
        if (mb_strlen($suffix) > $descriptionLimit) {
            throw new InvalidArgumentException('Provider description limit cannot contain the managed marker suffix.');
        }

        $description = $this->withoutReservedPrefix($sourceDescription ?? '');
        $available = $descriptionLimit - mb_strlen($suffix) - ($description === '' ? 0 : 2);
        $description = $this->truncate($description, max(0, $available));
        $rendered = $description === '' ? $suffix : $description."\n\n".$suffix;

        return new ManagedPlaylistMetadata($title, $rendered, $marker);
    }

    private function plainText(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value) !== '' ? trim($value) : 'Playlista';
    }

    private function withoutReservedPrefix(string $description): string
    {
        $description = str_replace("\r\n", "\n", $description);
        $description = str_replace("\r", "\n", $description);
        $lines = array_filter(
            explode("\n", $description),
            fn (string $line): bool => ! str_contains($line, ManagedExportMarker::PREFIX),
        );
        $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', implode("\n", $lines)) ?? '';

        return trim($description);
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit));
    }
}
