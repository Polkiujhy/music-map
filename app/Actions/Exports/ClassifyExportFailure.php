<?php

namespace App\Actions\Exports;

use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Models\ExportOperation;
use Illuminate\Support\Facades\DB;

final readonly class ClassifyExportFailure
{
    public function persist(ExportOperation $operation, PlaylistWriteFailure $failure): ExportOperationFailure
    {
        $code = $this->code($failure);

        DB::transaction(function () use ($operation, $code): void {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => $locked->provider_mutation_started_at === null
                    ? ExportOperationStatus::Failed
                    : ExportOperationStatus::Incomplete,
                'failure_code' => $code,
                'completed_at' => now(),
            ])->save();
        });

        return $code;
    }

    public function code(PlaylistWriteFailure $failure): ExportOperationFailure
    {
        return match ($failure) {
            PlaylistWriteFailure::AdmissionLimit => ExportOperationFailure::AdmissionLimit,
            PlaylistWriteFailure::AdmissionUnavailable => ExportOperationFailure::AdmissionUnavailable,
            PlaylistWriteFailure::ReconnectRequired => ExportOperationFailure::ReconnectRequired,
            PlaylistWriteFailure::MissingScope => ExportOperationFailure::MissingScope,
            PlaylistWriteFailure::StaleCredential => ExportOperationFailure::StaleCredential,
            PlaylistWriteFailure::AccessDenied => ExportOperationFailure::AccessDenied,
            PlaylistWriteFailure::TargetDeleted => ExportOperationFailure::TargetDeleted,
            PlaylistWriteFailure::RateLimited => ExportOperationFailure::RateLimited,
            PlaylistWriteFailure::QuotaLimited => ExportOperationFailure::QuotaLimited,
            PlaylistWriteFailure::TemporaryFailure => ExportOperationFailure::TemporaryFailure,
            PlaylistWriteFailure::InvalidResponse => ExportOperationFailure::InvalidResponse,
            PlaylistWriteFailure::UnsupportedDuplicate => ExportOperationFailure::UnsupportedDuplicate,
            PlaylistWriteFailure::AmbiguousCreate => ExportOperationFailure::AmbiguousCreate,
            PlaylistWriteFailure::RecoveryScanIncomplete => ExportOperationFailure::RecoveryScanIncomplete,
        };
    }

    public function automaticallyRetryable(ExportOperationFailure $failure): bool
    {
        return in_array($failure, [
            ExportOperationFailure::RateLimited,
            ExportOperationFailure::QuotaLimited,
            ExportOperationFailure::TemporaryFailure,
            ExportOperationFailure::AdmissionUnavailable,
            ExportOperationFailure::AmbiguousCreate,
            ExportOperationFailure::RecoveryScanIncomplete,
        ], true);
    }
}
