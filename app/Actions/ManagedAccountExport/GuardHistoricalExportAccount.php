<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Models\PlaylistExport;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class GuardHistoricalExportAccount
{
    public function handle(User $user, PlaylistExport $export): void
    {
        if ($export->destination_type === ExportDestinationType::Linked) {
            // A reconnect can replace the local row; the frozen provider
            // identity remains the authority for retry and recreation.
            $account = StreamingAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $export->target_provider->value)
                ->where('provider_account_id', $export->target_account_id)
                ->whereNotNull('refresh_token')
                ->lockForUpdate()
                ->first();

            if ($account !== null && array_diff($export->target_provider->exportScopes(), $account->scopes ?? []) === []) {
                return;
            }

            throw ValidationException::withMessages([
                'operation' => 'Połącz ponownie to samo konto docelowe i nadaj wymagane uprawnienia.',
            ]);
        }

        $currentAccount = (string) config("services.managed_export.providers.{$export->target_provider->value}.account_id");

        if ($export->destination_type !== ExportDestinationType::Managed
            || $currentAccount === ''
            || ! hash_equals($export->target_account_id, $currentAccount)) {
            throw ValidationException::withMessages([
                'operation' => 'Nie można potwierdzić dostępu do historycznego konta docelowego.',
            ]);
        }
    }
}
