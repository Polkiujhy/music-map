<?php

namespace App\Exceptions\Auth;

use RuntimeException;
use Throwable;

class IdentityConflictException extends RuntimeException
{
    public static function forGoogle(?Throwable $previous = null): self
    {
        return new self('Nie można bezpiecznie połączyć tego konta Google.', 0, $previous);
    }
}
