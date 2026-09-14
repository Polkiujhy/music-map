<?php

namespace App\Actions\Exports;

use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistExport\Actions\WithLinkedExportAccess;
use App\Integrations\PlaylistExport\Actions\WithManagedExportAccess;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\Contracts\WithExportAccess;
use App\Integrations\PlaylistExport\Data\ExportPlaylistDefinition;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\PlaylistExport\PlaylistWriterRegistry;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use App\Models\ExportReviewItem;
use App\Models\PlaylistExportLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final readonly class RunExportOperation
{
    public function __construct(
        private PlaylistWriterRegistry $writers,
        private WithLinkedExportAccess $linkedAccess,
        private WithManagedExportAccess $managedAccess,
        private AdmitYouTubeWrite $youtubeAdmission,
        private PersistExportTarget $targets,
        private ClassifyExportFailure $failures,
    ) {}

    public function handle(string $operationId): void
    {
        $operation = $this->claim($operationId);
        if (! $operation instanceof ExportOperation) {
            return;
        }

        try {
            $operation->load(['exportReview.items', 'playlistExportLink.targetPlaylist']);
            if ($operation->exportReview->status !== ExportReviewStatus::Confirmed) {
                $this->finishFailure($operation, PlaylistWriteFailure::InvalidResponse);

                return;
            }

            $failure = $this->accessFor($operation)->handle(
                $operation,
                fn (#[\SensitiveParameter] string $accessToken, ExportMutationGuard $guard): ?PlaylistWriteFailure => $this->execute($operation, $accessToken, $guard),
            );
        } catch (Throwable $exception) {
            Log::error('export_operation_unexpected_exception', [
                'operation_id' => $operation->operation_id,
                'exception_class' => $exception::class,
                'exception_location' => basename($exception->getFile()).':'.$exception->getLine(),
            ]);

            $operation->refresh();
            if ($operation->status === ExportOperationStatus::Transferred
                || ($operation->status === ExportOperationStatus::Failed && $operation->active_key === null)) {
                return;
            }

            $failure = PlaylistWriteFailure::TemporaryFailure;
        }

        if ($failure !== null) {
            $this->finishFailure($operation, $failure);
        }
    }

    private function claim(string $operationId): ?ExportOperation
    {
        return DB::transaction(function () use ($operationId): ?ExportOperation {
            $operation = ExportOperation::query()
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();
            if (! $operation instanceof ExportOperation
                || $operation->status === ExportOperationStatus::Transferred
                || ($operation->status === ExportOperationStatus::Failed && $operation->active_key === null)
                || $operation->status === ExportOperationStatus::Processing) {
                return null;
            }

            if (in_array($operation->status, [ExportOperationStatus::Failed, ExportOperationStatus::Incomplete], true)
                && ($operation->failure_code === null
                    || ! $this->failures->automaticallyRetryable($operation->failure_code)
                    || $operation->attempt_count >= ExecuteExportOperation::MAX_ATTEMPTS)) {
                return null;
            }

            if ($operation->playlist_export_link_id === null) {
                $link = PlaylistExportLink::query()
                    ->where('active_key', PlaylistExportLink::activeKey(
                        (int) $operation->source_playlist_id,
                        $operation->target_provider,
                        $operation->target_account_id,
                    ))
                    ->first();
                if ($link instanceof PlaylistExportLink) {
                    $operation->playlistExportLink()->associate($link);
                }
            }

            $operation->forceFill([
                'status' => ExportOperationStatus::Processing,
                'attempt_count' => $operation->attempt_count + 1,
                'started_at' => $operation->started_at ?? now(),
                'completed_at' => null,
            ])->save();

            return $operation;
        });
    }

    private function execute(
        ExportOperation $operation,
        #[\SensitiveParameter] string $accessToken,
        ExportMutationGuard $accessGuard,
    ): ?PlaylistWriteFailure {
        $writer = $this->writers->writerFor($operation->target_provider);
        $definition = $this->definition($operation);
        $admitted = false;

        if ($definition->targetId !== null) {
            $inspection = $writer->inspect($accessToken, $this->definition($operation));
            if (! $inspection->succeeded()) {
                if ($inspection->failure === PlaylistWriteFailure::TargetDeleted) {
                    $this->targets->retireDeletedTarget($operation);

                    return null;
                }

                return $inspection->failure;
            }
        } elseif ($operation->provider_mutation_started_at !== null
            || in_array($operation->failure_code, [
                ExportOperationFailure::AmbiguousCreate,
                ExportOperationFailure::RecoveryScanIncomplete,
            ], true)) {
            $recovery = $writer->recover($accessToken, $definition);
            if (! $recovery->succeeded() || $recovery->providerId === null) {
                return $recovery->failure;
            }

            $this->targets->checkpoint($operation, $recovery->providerId);
            $operation->refresh()->load(['exportReview.items', 'playlistExportLink.targetPlaylist']);
            $definition = $this->definition($operation);
        } else {
            if (($failure = $this->admitYouTube($operation)) !== null) {
                return $failure;
            }
            $admitted = $operation->target_provider === StreamingProvider::YouTube;

            $created = $writer->create(
                $accessToken,
                $definition,
                new CheckpointExportMutation($operation->operation_id, $accessGuard),
            );
            if (! $created->succeeded() || $created->providerId === null) {
                return $created->failure;
            }

            $this->targets->checkpoint($operation, $created->providerId);
            $operation->refresh()->load(['exportReview.items', 'playlistExportLink.targetPlaylist']);
            $definition = $this->definition($operation);
        }

        if ($operation->target_provider === StreamingProvider::YouTube && ! $admitted
            && ($failure = $this->admitYouTube($operation)) !== null) {
            return $failure;
        }

        $result = $writer->replace(
            $accessToken,
            $definition,
            new CheckpointExportMutation($operation->operation_id, $accessGuard),
        );
        if (! $result->succeeded()) {
            if ($result->failure === PlaylistWriteFailure::TargetDeleted) {
                $this->targets->retireDeletedTarget($operation);

                return null;
            }

            return $result->failure;
        }

        $operation->loadMissing('exportReview.items');
        $this->targets->complete($operation, $result);

        return null;
    }

    private function definition(ExportOperation $operation): ExportPlaylistDefinition
    {
        $items = $operation->exportReview->items
            ->filter(fn (ExportReviewItem $item): bool => $item->match_status !== ExportMatchStatus::Unavailable
                && ($item->decision ?? ExportReviewDecision::Keep) === ExportReviewDecision::Keep)
            ->values();

        return new ExportPlaylistDefinition(
            $operation->target_provider,
            $operation->target_account_id,
            $operation->playlistExportLink?->targetPlaylist?->source_playlist_id,
            $operation->playlist_name ?? '',
            $operation->playlist_description ?? '',
            '[music-map-export:'.$this->markerOperationId($operation).']',
            $operation->target_provider === StreamingProvider::Spotify ? 'private' : 'unlisted',
            $operation->target_provider === StreamingProvider::Spotify
                ? $items->pluck('target_catalog_uri')->all()
                : $items->pluck('target_catalog_id')->all(),
        );
    }

    private function markerOperationId(ExportOperation $operation): string
    {
        if ($operation->playlist_export_link_id === null) {
            return $operation->operation_id;
        }

        return (string) (ExportOperation::query()
            ->where('playlist_export_link_id', $operation->playlist_export_link_id)
            ->orderBy('id')
            ->value('operation_id') ?? $operation->operation_id);
    }

    private function admitYouTube(ExportOperation $operation): ?PlaylistWriteFailure
    {
        if ($operation->target_provider !== StreamingProvider::YouTube) {
            return null;
        }

        try {
            $result = $this->youtubeAdmission->admit(
                $operation->destination_type === ExportDestinationType::Linked
                    ? YouTubeWriteOperationType::LinkedExport
                    : YouTubeWriteOperationType::ManagedExport,
                $operation->operation_id,
            );
        } catch (YouTubeWriteAdmissionUnavailable) {
            return PlaylistWriteFailure::AdmissionUnavailable;
        }

        return $result->status === YouTubeWriteAdmissionStatus::LimitReached
            ? PlaylistWriteFailure::AdmissionLimit
            : null;
    }

    private function accessFor(ExportOperation $operation): WithExportAccess
    {
        return $operation->destination_type === ExportDestinationType::Linked
            ? $this->linkedAccess
            : $this->managedAccess;
    }

    private function finishFailure(ExportOperation $operation, PlaylistWriteFailure $failure): void
    {
        $code = $this->failures->persist($operation, $failure);
        $operation->refresh();

        if ($this->failures->automaticallyRetryable($code)
            && $operation->attempt_count < ExecuteExportOperation::MAX_ATTEMPTS) {
            throw new RuntimeException('Export operation requires a safe retry: '.$code->value);
        }
    }
}

final readonly class CheckpointExportMutation implements ExportMutationGuard
{
    public function __construct(
        private string $operationId,
        private ExportMutationGuard $guard,
    ) {}

    public function failure(): ?PlaylistWriteFailure
    {
        if (($failure = $this->guard->failure()) !== null) {
            return $failure;
        }

        $marked = ExportOperation::query()
            ->where('operation_id', $this->operationId)
            ->where('status', ExportOperationStatus::Processing->value)
            ->whereNull('provider_mutation_started_at')
            ->update([
                'provider_mutation_started_at' => now(),
                'updated_at' => now(),
            ]);
        if ($marked === 1) {
            return null;
        }

        return ExportOperation::query()
            ->where('operation_id', $this->operationId)
            ->where('status', ExportOperationStatus::Processing->value)
            ->whereNotNull('provider_mutation_started_at')
            ->exists()
                ? null
                : PlaylistWriteFailure::TemporaryFailure;
    }
}
