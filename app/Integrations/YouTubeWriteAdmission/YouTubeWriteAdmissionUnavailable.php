<?php

namespace App\Integrations\YouTubeWriteAdmission;

use RuntimeException;

final class YouTubeWriteAdmissionUnavailable extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('YouTube write admission is temporarily unavailable.', 0, $previous);
    }
}
