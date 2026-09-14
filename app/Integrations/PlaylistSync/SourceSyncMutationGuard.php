<?php

namespace App\Integrations\PlaylistSync;

use App\Enums\PlaylistSyncStatus;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;

final readonly class SourceSyncMutationGuard
{
    public function __construct(
        private int $runId,
        private int $accountId,
        private int $ownerId,
        private int $credentialVersion,
        private StreamingAccessContext $access,
    ) {}

    public function failure(): ?SourceSyncFailure
    {
        if ($this->access->expiresAt !== null && $this->access->expiresAt <= now()) {
            return SourceSyncFailure::ReconnectRequired;
        }

        $accountExists = StreamingAccount::query()
            ->whereKey($this->accountId)
            ->where('user_id', $this->ownerId)
            ->where('provider', $this->access->provider->value)
            ->where('provider_account_id', $this->access->providerAccountId)
            ->where('credential_version', $this->credentialVersion)
            ->whereNotNull('refresh_token')
            ->exists();
        if (! $accountExists) {
            return SourceSyncFailure::ReconnectRequired;
        }

        $enabled = PlaylistSyncRun::query()
            ->whereKey($this->runId)
            ->whereIn('state', ['pending', 'running'])
            ->whereHas('synchronization', function ($query): void {
                $query->where('status', PlaylistSyncStatus::Enabled->value)
                    ->where('streaming_account_id', $this->accountId)
                    ->whereHas('playlist', fn ($playlist) => $playlist->sourceOnly()
                        ->where('user_id', $this->ownerId)
                        ->where('source_provider', $this->access->provider->value));
            })
            ->exists();

        return $enabled ? null : SourceSyncFailure::Forbidden;
    }
}
