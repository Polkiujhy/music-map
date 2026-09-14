<?php

namespace App\Integrations\ManagedAccountExport\Data;

enum ManagedMarkerLookupStatus: string
{
    case None = 'none';
    case One = 'one';
    case Ambiguous = 'ambiguous';
    case Inconclusive = 'inconclusive';
}
