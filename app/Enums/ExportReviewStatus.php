<?php

namespace App\Enums;

enum ExportReviewStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
}
