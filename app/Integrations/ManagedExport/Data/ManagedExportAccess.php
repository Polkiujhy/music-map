<?php

namespace App\Integrations\ManagedExport\Data;

use DateTimeImmutable;

final readonly class ManagedExportAccess
{
    public function __construct(
        public string $provider,
        #[\SensitiveParameter]
        public string $accessToken,
        public DateTimeImmutable $expiresAt,
        public string $operationId,
    ) {}
}
