<?php

namespace App\Enums;

enum PlaylistSyncDirection: string
{
    case NoOp = 'no-op';
    case Pull = 'pull';
    case Push = 'push';
}
