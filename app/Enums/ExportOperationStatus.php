<?php

namespace App\Enums;

enum ExportOperationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Transferred = 'transferred';
    case Failed = 'failed';
    case Incomplete = 'incomplete';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Transferred, self::Failed], true);
    }
}
