<?php

namespace App\Integrations\PlaylistImport\Contracts;

use App\Integrations\PlaylistImport\Data\PlaylistReference;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;

interface PlaylistSourceReader
{
    public function read(PlaylistReference $reference): PlaylistSnapshot|ImportFailureCode;
}
