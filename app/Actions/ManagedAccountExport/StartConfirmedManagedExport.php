<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Integrations\ManagedAccountExport\ManagedExportMarker;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use Illuminate\Validation\ValidationException;

final readonly class StartConfirmedManagedExport
{
    public function __construct(private PublishManagedExport $publisher) {}

    public function handle(ExportReview $review, ConfirmedExportManifest $manifest): ?ExportOperation
    {
        if ($manifest->destinationType !== ExportDestinationType::Managed) {
            return null;
        }

        $existing = ExportOperation::query()
            ->where('export_review_id', $review->getKey())
            ->first();

        if ($existing instanceof ExportOperation) {
            return $existing;
        }

        $playlistExport = PlaylistExport::query()
            ->where('source_playlist_id', $manifest->playlistId)
            ->where('target_provider', $manifest->targetProvider->value)
            ->where('target_account_id', $manifest->targetAccountId)
            ->lockForUpdate()
            ->first();

        if ($playlistExport instanceof PlaylistExport) {
            $blocking = $playlistExport->operations()
                ->where('export_review_id', '!=', $review->getKey())
                ->whereIn('status', [
                    ExportOperationStatus::Queued->value,
                    ExportOperationStatus::Processing->value,
                    ExportOperationStatus::PartialFailed->value,
                    ExportOperationStatus::ManualRecoveryRequired->value,
                    ExportOperationStatus::RecreateRequired->value,
                ])
                ->exists();

            if ($blocking) {
                throw ValidationException::withMessages([
                    'review' => 'Ten cel ma już aktywną lub niedokończoną operację eksportu.',
                ]);
            }
        } else {
            $playlistExport = PlaylistExport::query()->create([
                'source_playlist_id' => $manifest->playlistId,
                'target_provider' => $manifest->targetProvider,
                'destination_type' => $manifest->destinationType,
                'streaming_account_id' => $review->streaming_account_id,
                'target_account_id' => $manifest->targetAccountId,
                'target_market' => $manifest->targetMarket,
                'target_generation' => 1,
            ]);
        }

        $attempt = $playlistExport->targetAttempts()
            ->where('generation', $playlistExport->target_generation)
            ->first();

        if (! $attempt instanceof PlaylistExportTargetAttempt) {
            $playlistExport->targetAttempts()->create([
                'target_provider' => $playlistExport->target_provider,
                'target_account_id' => $playlistExport->target_account_id,
                'generation' => $playlistExport->target_generation,
                'marker' => ManagedExportMarker::generate(),
                'status' => PlaylistExportTargetAttempt::STATUS_PENDING,
            ]);
        }

        $operation = ExportOperation::query()->create([
            'user_id' => $review->user_id,
            'export_review_id' => $review->getKey(),
            'playlist_export_id' => $playlistExport->getKey(),
            'status' => ExportOperationStatus::Queued,
        ]);

        $this->publisher->afterCommit((string) $operation->getKey());

        return $operation;
    }
}
