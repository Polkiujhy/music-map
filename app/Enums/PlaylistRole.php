<?php

namespace App\Enums;

enum PlaylistRole: string
{
    case Source = 'source';
    case ExportTarget = 'export_target';
}
