<?php

namespace App\Integrations\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessResult;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\ExportOperation;
use App\Models\StreamingAccount;
use Closure;

final readonly class WithLinkedAccountExportAccess
{
    public function __construct(private WithStreamingAccess $access) {}

    public function handle(ExportOperation $operation, Closure $callback): ManagedAccessResult
    {
        $export = $operation->playlistExport;
        $owner = $operation->user;
        if ($export->destination_type !== ExportDestinationType::Linked || $owner === null) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::AccountMismatch);
        }

        // A same-identity reconnect may replace the local account row. The
        // durable provider account identity, never the current default, wins.
        $account = StreamingAccount::query()
            ->where('user_id', $owner->getKey())
            ->where('provider', $export->target_provider->value)
            ->where('provider_account_id', $export->target_account_id)
            ->whereNotNull('refresh_token')->first();
        if ($account === null) {
            return ManagedAccessResult::failure(ManagedExportFailureCode::AuthenticationRequired);
        }

        $failure = null;
        $result = $this->access->handle($owner, $account, $export->target_provider->exportScopes(),
            function (StreamingAccessContext $access) use ($operation, $export, $account, $callback, &$failure): void {
                $snapshot = $account->fresh();
                if ($snapshot === null || $snapshot->refresh_token === null
                    || $access->provider !== $export->target_provider
                    || ! hash_equals($export->target_account_id, $access->providerAccountId)
                    || ! hash_equals($export->target_account_id, $snapshot->provider_account_id)) {
                    $failure = ManagedExportFailureCode::AccountMismatch;

                    return;
                }
                $version = $access->credentialVersion ?? $snapshot->credential_version;
                if ($access->expiresAt === null || $access->expiresAt < now()->addSeconds(390)) {
                    $failure = ManagedExportFailureCode::AuthenticationRequired;

                    return;
                }
                $context = new ManagedAccessContext($access->provider, $access->providerAccountId,
                    $access->accessToken, $access->expiresAt,
                    (string) $operation->getKey(),
                    fn (): ?ManagedExportFailureCode => StreamingAccount::query()
                        ->whereKey($snapshot->getKey())->where('user_id', $operation->user_id)
                        ->where('provider', $export->target_provider->value)
                        ->where('provider_account_id', $export->target_account_id)
                        ->where('credential_version', $version)->whereNotNull('refresh_token')->exists()
                            ? null : ManagedExportFailureCode::AuthenticationRequired,
                    requireTargetMarker: false);
                $failure = $callback($context);
            });

        if ($result->successful) {
            if ($failure !== null && ! $failure instanceof ManagedExportFailureCode) {
                return ManagedAccessResult::failure(ManagedExportFailureCode::InvalidResponse);
            }

            return $failure instanceof ManagedExportFailureCode
                ? ManagedAccessResult::failure($failure) : ManagedAccessResult::success();
        }
        $code = match ($result->failure) {
            StreamingAccessFailure::MissingScope => ManagedExportFailureCode::RequiredScopeMissing,
            StreamingAccessFailure::RateLimited => ManagedExportFailureCode::RateLimited,
            StreamingAccessFailure::QuotaExceeded => ManagedExportFailureCode::QuotaExceeded,
            StreamingAccessFailure::TemporarilyUnavailable => ManagedExportFailureCode::TransportUnavailable,
            default => ManagedExportFailureCode::AuthenticationRequired,
        };

        return ManagedAccessResult::failure($code, in_array($code, [ManagedExportFailureCode::RateLimited, ManagedExportFailureCode::TransportUnavailable], true));
    }
}
