<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookupStatus;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Models\ExportOperation;
use App\Models\PlaylistExportTargetAttempt;
use Illuminate\Support\Facades\DB;

final readonly class ResolveManagedExportTarget
{
    public function handle(
        ExportOperation $operation,
        int $attemptGeneration,
        ManagedAccessContext $access,
        ManagedPlaylistGateway $gateway,
        ManagedPlaylistMetadata $metadata,
    ): ManagedPlaylistReference|ManagedProviderFailure|ManagedExportFailureCode {
        $operation->loadMissing('playlistExport.targetAttempts');
        $export = $operation->playlistExport;
        $attempt = $export->targetAttempts
            ->first(fn (PlaylistExportTargetAttempt $candidate): bool => $candidate->generation === $export->target_generation
                && $candidate->status !== PlaylistExportTargetAttempt::STATUS_ABANDONED);

        if (! $attempt instanceof PlaylistExportTargetAttempt) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
        }

        if ($attempt->provider_playlist_id !== null) {
            if ($attempt->canonical_url === null) {
                return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse);
            }

            $reference = new ManagedPlaylistReference(
                $export->target_provider,
                $attempt->provider_playlist_id,
                $attempt->canonical_url,
                $export->target_account_id,
            );
            $inspected = $gateway->inspect($access, $reference);

            if (! $inspected instanceof ManagedProviderFailure
                && $inspected->metadata->marker !== $attempt->marker) {
                return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
            }

            return $inspected instanceof ManagedProviderFailure ? $inspected : $reference;
        }

        $lookup = $gateway->findByMarker($access, $attempt->marker);
        if ($lookup instanceof ManagedProviderFailure) {
            return $lookup;
        }

        if ($lookup->status === ManagedMarkerLookupStatus::Ambiguous) {
            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation);
        }
        if ($lookup->status === ManagedMarkerLookupStatus::Inconclusive) {
            return new ManagedProviderFailure(ManagedExportFailureCode::TransportUnavailable, true, 300);
        }
        if ($lookup->status === ManagedMarkerLookupStatus::One) {
            $reference = $lookup->matches[0];
            if (! $this->validReference($reference, $operation)) {
                return new ManagedProviderFailure(ManagedExportFailureCode::AccountMismatch);
            }
            $inspected = $gateway->inspect($access, $reference);
            if ($inspected instanceof ManagedProviderFailure) {
                return $inspected;
            }
            if ($inspected->metadata->marker !== $attempt->marker) {
                return new ManagedProviderFailure(ManagedExportFailureCode::TargetMarkerMismatch);
            }

            return $this->checkpoint($operation, $attempt, $attemptGeneration, $reference)
                ? $reference
                : ManagedExportFailureCode::PersistenceFailure;
        }

        // A prior create with no locator is intentionally scan-only forever.
        if ($attempt->status !== PlaylistExportTargetAttempt::STATUS_PENDING
            || $attempt->create_started_at !== null) {
            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation, true, 300);
        }

        $marked = DB::transaction(function () use ($operation, $attempt, $attemptGeneration): bool {
            $current = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('attempt_generation', $attemptGeneration)
                ->where('status', ExportOperationStatus::Processing->value)
                ->lockForUpdate()
                ->first();
            $currentAttempt = PlaylistExportTargetAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if (! $current instanceof ExportOperation
                || ! $currentAttempt instanceof PlaylistExportTargetAttempt
                || $currentAttempt->create_started_at !== null
                || $currentAttempt->status !== PlaylistExportTargetAttempt::STATUS_PENDING) {
                return false;
            }

            $currentAttempt->forceFill(['create_started_at' => now()])->save();
            $current->forceFill(['possible_mutation_at' => now(), 'heartbeat_at' => now()])->save();

            return true;
        });

        if (! $marked) {
            return ManagedExportFailureCode::PersistenceFailure;
        }

        $reference = $gateway->create($access, $metadata);
        if ($reference instanceof ManagedProviderFailure) {
            if ($reference->code === ManagedExportFailureCode::AmbiguousMutation) {
                $this->markUnknown($operation, $attempt, $attemptGeneration);

                return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation, true, 300);
            }

            return $reference;
        }

        if (! $this->validReference($reference, $operation)) {
            $this->markUnknown($operation, $attempt, $attemptGeneration);

            return new ManagedProviderFailure(ManagedExportFailureCode::AmbiguousMutation);
        }

        return $this->checkpoint($operation, $attempt, $attemptGeneration, $reference)
            ? $reference
            : ManagedExportFailureCode::PersistenceFailure;
    }

    private function checkpoint(
        ExportOperation $operation,
        PlaylistExportTargetAttempt $attempt,
        int $generation,
        ManagedPlaylistReference $reference,
    ): bool {
        return DB::transaction(function () use ($operation, $attempt, $generation, $reference): bool {
            $active = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('attempt_generation', $generation)
                ->where('status', ExportOperationStatus::Processing->value)
                ->lockForUpdate()
                ->exists();
            $current = PlaylistExportTargetAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

            if (! $active || ! $current instanceof PlaylistExportTargetAttempt) {
                return false;
            }
            if ($current->provider_playlist_id !== null) {
                return hash_equals($current->provider_playlist_id, $reference->providerPlaylistId);
            }

            $current->forceFill([
                'status' => PlaylistExportTargetAttempt::STATUS_RESOLVED,
                'create_completed_at' => now(),
                'provider_playlist_id' => $reference->providerPlaylistId,
                'canonical_url' => $reference->canonicalUrl,
            ])->save();

            return true;
        });
    }

    private function validReference(ManagedPlaylistReference $reference, ExportOperation $operation): bool
    {
        return $reference->provider === $operation->playlistExport->target_provider
            && hash_equals($operation->playlistExport->target_account_id, $reference->ownerAccountId);
    }

    private function markUnknown(
        ExportOperation $operation,
        PlaylistExportTargetAttempt $attempt,
        int $generation,
    ): void {
        DB::transaction(function () use ($operation, $attempt, $generation): void {
            $active = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('attempt_generation', $generation)
                ->where('status', ExportOperationStatus::Processing->value)
                ->lockForUpdate()
                ->exists();
            if ($active) {
                PlaylistExportTargetAttempt::query()->whereKey($attempt->getKey())->update([
                    'status' => PlaylistExportTargetAttempt::STATUS_UNKNOWN,
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
