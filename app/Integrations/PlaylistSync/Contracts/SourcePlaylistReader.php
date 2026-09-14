<?php

namespace App\Integrations\PlaylistSync\Contracts;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;

interface SourcePlaylistReader
{
    public function provider(): StreamingProvider;

    public function read(
        string $providerPlaylistId,
        StreamingAccessContext $access,
    ): SourcePlaylistSnapshot|SourceSyncFailure;
}
