<?php

namespace App\Enums;

enum PlaylistOrigin: string
{
    case Imported = 'imported';
    case ManagedTarget = 'managed_target';
}
