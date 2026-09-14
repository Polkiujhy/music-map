<?php

namespace App\Integrations\YouTubeWriteAdmission;

enum YouTubeWriteOperationType: string
{
    case ManagedExport = 'managed-export';
    case LinkedExport = 'linked-export';
    case SourceSync = 'source-sync';
    case DriftRecovery = 'drift-recovery';
}
