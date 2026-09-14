<?php

namespace App\Actions\Playlists;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateBankPlaylistItems
{
    public function __construct(private FingerprintPlaylistContent $fingerprint) {}

    /**
     * @param  list<int>  $orderedItemIds
     */
    public function handle(
        User $owner,
        int $playlistId,
        string $expectedFingerprint,
        array $orderedItemIds,
        bool $emptyResultConfirmed = false,
    ): Playlist {
        return DB::transaction(function () use (
            $owner,
            $playlistId,
            $expectedFingerprint,
            $orderedItemIds,
            $emptyResultConfirmed,
        ): Playlist {
            $playlist = $owner->playlists()
                ->sourceOnly()
                ->whereKey($playlistId)
                ->lockForUpdate()
                ->first();

            if ($playlist === null) {
                throw new PlaylistEditConflict(PlaylistEditConflict::PLAYLIST_NOT_FOUND);
            }

            $playlist->setRelation('items', $playlist->items()->get());

            if (! hash_equals($this->fingerprint->handle($playlist), $expectedFingerprint)) {
                throw new PlaylistEditConflict(PlaylistEditConflict::CONTENT_CHANGED);
            }

            if (count($orderedItemIds) > 20) {
                throw new PlaylistEditConflict(PlaylistEditConflict::TOO_MANY_ITEMS);
            }

            if ($orderedItemIds === [] && ! $emptyResultConfirmed) {
                throw new PlaylistEditConflict(PlaylistEditConflict::EMPTY_CONFIRMATION_REQUIRED);
            }

            if ($this->containsInvalidOrDuplicateIds($orderedItemIds)) {
                throw new PlaylistEditConflict(PlaylistEditConflict::INVALID_ITEM_SELECTION);
            }

            $currentItems = $playlist->items;
            $currentItemIds = $currentItems->modelKeys();

            if (array_diff($orderedItemIds, $currentItemIds) !== []) {
                throw new PlaylistEditConflict(PlaylistEditConflict::INVALID_ITEM_SELECTION);
            }

            if ($orderedItemIds === $currentItemIds) {
                return $playlist;
            }

            $temporaryStart = max(0, (int) $currentItems->max('position')) + $currentItems->count() + 1;

            foreach ($currentItems->values() as $ordinal => $item) {
                $item->update(['position' => $temporaryStart + $ordinal]);
            }

            $playlist->items()
                ->whereNotIn('id', $orderedItemIds)
                ->delete();

            $itemsById = $currentItems->keyBy(fn ($item): int => (int) $item->getKey());

            foreach ($orderedItemIds as $position => $itemId) {
                $itemsById->get($itemId)->update(['position' => $position]);
            }

            $playlist->update(['bank_content_edited_at' => now()]);

            return $playlist->refresh()->load('items');
        });
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function containsInvalidOrDuplicateIds(array $itemIds): bool
    {
        foreach ($itemIds as $itemId) {
            if (! is_int($itemId) || $itemId <= 0) {
                return true;
            }
        }

        return count($itemIds) !== count(array_unique($itemIds, SORT_REGULAR));
    }
}
