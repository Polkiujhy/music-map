<?php

namespace App\Enums;

enum PlaylistSyncOutcome: string
{
    case NoOp = 'no-op';
    case Pulled = 'pulled';
    case Pushed = 'pushed';
    case Failed = 'failed';
}
