<?php

namespace App\Integrations\YouTubeWriteAdmission\Contracts;

use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;

interface AdmitYouTubeWrite
{
    public const MAX_OPERATION_ID_LENGTH = 255;

    /** @throws YouTubeWriteAdmissionUnavailable */
    public function admit(
        YouTubeWriteOperationType $operationType,
        string $operationId,
    ): YouTubeWriteAdmissionResult;
}
