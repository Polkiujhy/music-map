<?php

namespace App\Enums;

enum ExportMatchStatus: string
{
    case Matched = 'matched';
    case Suspicious = 'suspicious';
    case Unavailable = 'unavailable';
}
