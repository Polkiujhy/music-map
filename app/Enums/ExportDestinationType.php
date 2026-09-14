<?php

namespace App\Enums;

enum ExportDestinationType: string
{
    case Linked = 'linked';
    case Managed = 'managed';
}
