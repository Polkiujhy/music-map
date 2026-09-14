<?php

namespace App\Integrations\PlaylistExport\Actions;

use App\Enums\ExportDestinationType;
use App\Integrations\PlaylistExport\Contracts\WithExportAccess;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\ExportOperation;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;

final readonly class WithLinkedExportAccess implements WithExportAccess
{
    public function __construct(private WithStreamingAccess $access) {}

    public function handle(ExportOperation $operation, Closure $callback): ?PlaylistWriteFailure
    {
        if ($operation->destination_type !== ExportDestinationType::Linked) {
            return PlaylistWriteFailure::AccessDenied;
        }

        $owner = User::query()->find($operation->user_id);
        $account = StreamingAccount::query()
            ->where('user_id', $operation->user_id)
            ->where('provider', $operation->target_provider->value)
            ->where('provider_account_id', $operation->target_account_id)
            ->whereNotNull('refresh_token')
            ->when(
                $operation->streaming_account_id !== null,
                fn ($query) => $query->whereKey($operation->streaming_account_id),
            )
            ->first();

        if (! $owner instanceof User || ! $account instanceof StreamingAccount) {
            return PlaylistWriteFailure::ReconnectRequired;
        }

        $callbackFailure = null;
        $result = $this->access->handle(
            $owner,
            $account,
            $operation->target_provider->requiredScopes(),
            function (StreamingAccessContext $context) use ($operation, $account, $callback, &$callbackFailure): void {
                $snapshot = StreamingAccount::query()
                    ->whereKey($account->getKey())
                    ->where('user_id', $operation->user_id)
                    ->where('provider', $operation->target_provider->value)
                    ->where('provider_account_id', $operation->target_account_id)
                    ->whereNotNull('refresh_token')
                    ->first();

                if (! $snapshot instanceof StreamingAccount
                    || $context->provider !== $operation->target_provider
                    || ! hash_equals($operation->target_account_id, $context->providerAccountId)) {
                    $callbackFailure = PlaylistWriteFailure::StaleCredential;

                    return;
                }

                $callbackFailure = $callback(
                    $context->accessToken,
                    new LinkedExportMutationGuard(
                        (int) $operation->user_id,
                        (int) $snapshot->getKey(),
                        $operation->target_provider,
                        $operation->target_account_id,
                        (int) $snapshot->credential_version,
                    ),
                );
            },
        );

        return $result->successful
            ? $callbackFailure
            : $this->mapFailure($result->failure);
    }

    private function mapFailure(?StreamingAccessFailure $failure): PlaylistWriteFailure
    {
        return match ($failure) {
            StreamingAccessFailure::ReconnectRequired => PlaylistWriteFailure::ReconnectRequired,
            StreamingAccessFailure::MissingScope => PlaylistWriteFailure::MissingScope,
            StreamingAccessFailure::StaleCredential => PlaylistWriteFailure::StaleCredential,
            StreamingAccessFailure::RateLimited => PlaylistWriteFailure::RateLimited,
            StreamingAccessFailure::QuotaExceeded => PlaylistWriteFailure::QuotaLimited,
            default => PlaylistWriteFailure::TemporaryFailure,
        };
    }
}
