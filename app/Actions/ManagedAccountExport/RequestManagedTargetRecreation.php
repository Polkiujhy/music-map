<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Integrations\ManagedAccountExport\ManagedExportMarker;
use App\Models\ExportOperation;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RequestManagedTargetRecreation
{
    public function __construct(private PublishManagedExport $publisher) {}

    public function handle(User $user, ExportOperation $operation): ExportOperation
    {
        $operation = DB::transaction(function () use ($user, $operation): ExportOperation {
            $locked = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $export = PlaylistExport::query()->whereKey($locked->playlist_export_id)->lockForUpdate()->firstOrFail();
            $this->guardManagedAccount($export);

            if (! in_array($locked->status, [
                ExportOperationStatus::ManualRecoveryRequired,
                ExportOperationStatus::RecreateRequired,
            ], true)) {
                throw ValidationException::withMessages([
                    'operation' => 'Odtworzenie celu wymaga stanu ręcznego odzyskania albo utraty celu.',
                ]);
            }

            if ($locked->retry_available_at !== null && $locked->retry_available_at->isFuture()) {
                throw ValidationException::withMessages([
                    'operation' => 'Odtworzenie nie jest jeszcze dostępne. Spróbuj po wskazanym czasie.',
                ]);
            }

            $attempt = PlaylistExportTargetAttempt::query()
                ->where('playlist_export_id', $export->getKey())
                ->where('generation', $export->target_generation)
                ->lockForUpdate()
                ->firstOrFail();
            $attempt->forceFill(['status' => PlaylistExportTargetAttempt::STATUS_ABANDONED])->save();

            $nextGeneration = $export->target_generation + 1;
            $export->forceFill(['target_generation' => $nextGeneration])->save();
            $export->targetAttempts()->create([
                'target_provider' => $export->target_provider,
                'target_account_id' => $export->target_account_id,
                'generation' => $nextGeneration,
                'marker' => ManagedExportMarker::generate(),
                'status' => PlaylistExportTargetAttempt::STATUS_PENDING,
            ]);

            $locked->forceFill([
                'status' => ExportOperationStatus::Queued,
                'failure_code' => null,
                'retry_generation' => $locked->retry_generation + 1,
                'automatic_claim_count' => 0,
                'completed_at' => null,
                'heartbeat_at' => null,
                'possible_mutation_at' => null,
                'job_publication_lease_until' => null,
                'job_published_at' => null,
                'retry_available_at' => null,
                'manual_recovery_requested_at' => now(),
            ])->save();

            $this->publisher->afterCommit((string) $locked->getKey());

            return $locked;
        });

        return $operation->refresh();
    }

    private function guardManagedAccount(PlaylistExport $export): void
    {
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
