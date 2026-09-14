<?php

namespace App\Integrations\PlaylistExport\Actions;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Models\StreamingAccount;

final readonly class LinkedExportMutationGuard implements ExportMutationGuard
{
    public function __construct(
        private int $ownerId,
        private int $streamingAccountId,
        private StreamingProvider $provider,
        private string $targetAccountId,
        private int $credentialVersion,
    ) {}

    public function failure(): ?PlaylistWriteFailure
    {
        $account = StreamingAccount::query()
            ->whereKey($this->streamingAccountId)
            ->where('user_id', $this->ownerId)
            ->where('provider', $this->provider->value)
            ->where('provider_account_id', $this->targetAccountId)
            ->where('credential_version', $this->credentialVersion)
            ->whereNotNull('refresh_token')
            ->first();

        return $account instanceof StreamingAccount
            ? null
            : PlaylistWriteFailure::StaleCredential;
    }
}
