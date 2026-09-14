<?php

namespace App\Actions\Playlists;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;

final readonly class ReconcileEditedYouTubePlaylist
{
    public function __construct(
        private ReplaceImportedPlaylist $replace,
    ) {}

    public function handle(int $playlistId, PlaylistSnapshot $snapshot): Playlist|ImportFailureCode|null
    {
        return DB::transaction(function () use ($playlistId, $snapshot): Playlist|ImportFailureCode|null {
            $playlist = Playlist::query()
                ->sourceOnly()
                ->whereKey($playlistId)
                ->where('source_provider', StreamingProvider::YouTube->value)
                ->with('user')
                ->lockForUpdate()
                ->first();

            if ($playlist === null) {
                return null;
            }

            if ($snapshot->provider !== StreamingProvider::YouTube
                || $snapshot->providerPlaylistId !== $playlist->source_playlist_id) {
                return ImportFailureCode::InvalidResponse;
            }

            if ($playlist->bank_content_edited_at === null) {
                return $this->replace->handle($playlist->user, $snapshot, markImported: false);
            }

            $sourceItems = [];

            foreach ($snapshot->items as $item) {
                if (! $item instanceof PlaylistItemSnapshot
                    || $item->occurrenceId === null
                    || $item->occurrenceId === ''
                    || array_key_exists($item->occurrenceId, $sourceItems)) {
                    return ImportFailureCode::InvalidResponse;
                }

                $sourceItems[$item->occurrenceId] = $item;
            }

            $items = $playlist->items()->lockForUpdate()->get();
            $seenOccurrences = [];

            foreach ($items as $item) {
                if (! is_string($item->occurrence_id)
                    || $item->occurrence_id === ''
                    || array_key_exists($item->occurrence_id, $seenOccurrences)) {
                    return ImportFailureCode::InvalidResponse;
                }

                $seenOccurrences[$item->occurrence_id] = true;
            }

            foreach ($items as $item) {
                $freshItem = $sourceItems[$item->occurrence_id] ?? null;

                $item->update($freshItem === null
                    ? $this->placeholderAttributes()
                    : $this->freshAttributes($freshItem));
            }

            $playlist->update([
                'source_account_id' => $snapshot->sourceAccountId,
                'name' => $snapshot->name,
                'description' => $snapshot->description,
                'provider_metadata_refreshed_at' => $snapshot->providerMetadataRefreshedAt,
            ]);

            return $playlist->refresh()->load('items');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function freshAttributes(PlaylistItemSnapshot $item): array
    {
        return [
            'catalog_id' => $item->catalogId,
            'catalog_uri' => $item->catalogUri,
            'title' => $item->title,
            'creators' => $item->creators,
            'album' => $item->album,
            'duration_milliseconds' => $item->durationMilliseconds,
            'isrc' => $item->isrc,
            'is_available' => $item->isAvailable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderAttributes(): array
    {
        return [
            'catalog_id' => null,
            'catalog_uri' => null,
            'title' => null,
            'creators' => [],
            'album' => null,
            'duration_milliseconds' => null,
            'isrc' => null,
            'is_available' => false,
        ];
    }
}
