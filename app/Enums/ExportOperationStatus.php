<?php

namespace App\Enums;

enum ExportOperationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case PartialFailed = 'partial_failed';
    case ManualRecoveryRequired = 'manual_recovery_required';
    case RecreateRequired = 'recreate_required';
}
