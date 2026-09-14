<?php

namespace App\Integrations\PlaylistExport\Contracts;

use App\Integrations\PlaylistExport\Data\CreateRecoveryResult;
use App\Integrations\PlaylistExport\Data\ExportPlaylistDefinition;
use App\Integrations\PlaylistExport\Data\PlaylistWriteResult;

interface PlaylistWriter
{
    public function inspect(string $accessToken, ExportPlaylistDefinition $playlist): PlaylistWriteResult;

    public function recover(string $accessToken, ExportPlaylistDefinition $playlist): CreateRecoveryResult;

    public function create(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
    ): PlaylistWriteResult;

    public function replace(
        string $accessToken,
        ExportPlaylistDefinition $playlist,
        ExportMutationGuard $guard,
    ): PlaylistWriteResult;
}
