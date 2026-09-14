<?php

namespace App\Integrations\PlaylistImport\Contracts;

use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;

interface PlaylistSourceReader
{
    public function read(
        PlaylistReference $reference,
        ?StreamingAccessContext $access = null,
    ): PlaylistSnapshot|ImportFailureCode;
}
