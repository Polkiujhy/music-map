<?php

namespace App\Integrations\YouTubeWriteAdmission;

enum YouTubeWriteAdmissionStatus: string
{
    case AdmittedNew = 'admitted-new';
    case AdmittedExisting = 'admitted-existing';
    case LimitReached = 'limit-reached';
}
