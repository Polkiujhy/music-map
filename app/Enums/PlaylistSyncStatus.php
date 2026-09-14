<?php

namespace App\Enums;

enum PlaylistSyncStatus: string
{
    case PendingConfirmation = 'pending-confirmation';
    case Enabled = 'enabled';
    case Attention = 'attention';
    case Disabled = 'disabled';
}
