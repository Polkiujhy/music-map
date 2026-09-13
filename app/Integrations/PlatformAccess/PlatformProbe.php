<?php

namespace App\Integrations\PlatformAccess;

interface PlatformProbe
{
    public function probe(TechnicalConfiguration|TesterSession $input): ProbeResult|ProbeFailure;
}
