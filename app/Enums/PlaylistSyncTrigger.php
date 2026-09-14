<?php

namespace App\Enums;

enum PlaylistSyncTrigger: string
{
    case Activation = 'activation';
    case Manual = 'manual';
    case Automatic = 'automatic';
    case Login = 'login';
}
