<?php

namespace App\Integrations\StreamingAccounts\Contracts;

use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;

interface StreamingOAuthGateway
{
    public function authorizationUrl(string $state): string;

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure;

    public function refresh(string $refreshToken): StreamingGrant|StreamingOAuthFailure;

    public function identity(string $accessToken): StreamingIdentity|StreamingOAuthFailure;

    public function revoke(string $token): ?StreamingOAuthFailure;
}
