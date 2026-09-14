<?php

namespace App\Integrations\ManagedExport;

use RuntimeException;

final class ManagedExportAccessException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($category);
    }
}
