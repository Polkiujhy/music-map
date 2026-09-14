<?php

namespace App\Actions\Playlists;

use RuntimeException;

final class PlaylistEditConflict extends RuntimeException
{
    public const PLAYLIST_NOT_FOUND = 'playlist_not_found';

    public const CONTENT_CHANGED = 'content_changed';

    public const INVALID_ITEM_SELECTION = 'invalid_item_selection';

    public const EMPTY_CONFIRMATION_REQUIRED = 'empty_confirmation_required';

    public const TOO_MANY_ITEMS = 'too_many_items';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            self::PLAYLIST_NOT_FOUND => 'The playlist is unavailable to this owner.',
            self::CONTENT_CHANGED => 'The playlist content changed before the edit was saved.',
            self::INVALID_ITEM_SELECTION => 'The item selection is not a valid subset of the playlist.',
            self::EMPTY_CONFIRMATION_REQUIRED => 'Saving an empty playlist requires explicit confirmation.',
            self::TOO_MANY_ITEMS => 'A playlist edit cannot contain more than 20 items.',
            default => 'The playlist edit could not be applied safely.',
        });
    }
}
