<?php

namespace App\Integrations\PlaylistSync\Contracts;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\SourceSyncMutationGuard;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\PlaylistSyncRun;

interface SourcePlaylistWriter
{
    public function provider(): StreamingProvider;

    /** @param list<array<string, mixed>> $desiredItems */
    public function write(
        string $providerPlaylistId,
        SourcePlaylistSnapshot $current,
        array $desiredItems,
        StreamingAccessContext $access,
        ?PlaylistSyncRun $run = null,
        ?SourceSyncMutationGuard $guard = null,
    ): SourcePlaylistSnapshot|SourceSyncFailure;
}
