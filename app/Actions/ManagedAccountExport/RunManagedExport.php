<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\WithManagedAccountAccess;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistGatewayRegistry;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Integrations\ManagedAccountExport\Providers\SpotifyManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Providers\YouTubeManagedPlaylistGateway;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\ExportOperation;
use App\Models\PlaylistExportTargetAttempt;
use Illuminate\Support\Facades\DB;

final readonly class RunManagedExport
{
    private const LEASE_SECONDS = 510;

    private const MAX_AUTOMATIC_CLAIMS = 3;

    public function __construct(
        private AdmitYouTubeWrite $youtubeAdmission,
        private WithManagedAccountAccess $access,
        private ManagedPlaylistGatewayRegistry $gateways,
        private ReconcileManagedExport $reconcile,
    ) {}

    /** Return a bounded delay when the durable operation should be delivered again. */
    public function handle(string $operationId, ?int $retryGeneration = null): ?int
    {
        $claim = $this->claim($operationId, $retryGeneration);
        if ($claim === null) {
            return null;
        }

        [$operation, $generation] = $claim;
        $export = $operation->playlistExport;

        if ($export->target_provider === StreamingProvider::YouTube) {
            try {
                $admission = $this->youtubeAdmission->admit(
                    YouTubeWriteOperationType::ManagedExport,
                    $operation->getKey(),
                );
            } catch (YouTubeWriteAdmissionUnavailable) {
                return $this->fail($operation, $generation, ManagedExportFailureCode::TransportUnavailable, true, 300);
            }

            if ($admission->status === YouTubeWriteAdmissionStatus::LimitReached) {
                return $this->fail($operation, $generation, ManagedExportFailureCode::QuotaExceeded, false);
            }
        }

        $gateway = $this->gateways->for($export->target_provider);
        $failure = null;
        $result = $this->access->handle(
            $export->target_provider,
            $operation->getKey(),
            $export->target_account_id,
            $this->requiredScopes($export->target_provider),
            function (ManagedAccessContext $context) use ($operation, $generation, $gateway, &$failure): ?ManagedExportFailureCode {
                $outcome = $this->reconcile->handle($operation, $generation, $context, $gateway);
                if ($outcome instanceof ManagedProviderFailure) {
                    $failure = $outcome;

                    return $outcome->code;
                }
                if ($outcome instanceof ManagedExportFailureCode) {
                    return $outcome;
                }

                return null;
            },
        );

        if ($result->successful) {
            return null;
        }

        $code = $result->failure ?? ManagedExportFailureCode::InvalidResponse;
        $retryable = $failure?->retryable ?? $result->retryable;

        return $this->fail(
            $operation,
            $generation,
            $code,
            $retryable,
            $failure?->retryAfter ?? $result->retryAfter,
        );
    }

    /** @return null|array{ExportOperation, int} */
    private function claim(string $operationId, ?int $retryGeneration): ?array
    {
        return DB::transaction(function () use ($operationId, $retryGeneration): ?array {
            $query = ExportOperation::query()->whereKey($operationId);
            if ($retryGeneration !== null) {
                $query->where('retry_generation', $retryGeneration);
            }
            $operation = $query
                ->with('playlistExport')
                ->lockForUpdate()
                ->first();

            if (! $operation instanceof ExportOperation) {
                return null;
            }

            $recoverable = ($operation->status === ExportOperationStatus::Queued
                    && ($operation->retry_available_at === null || $operation->retry_available_at->isPast()))
                || ($operation->status === ExportOperationStatus::Processing
                    && ($operation->heartbeat_at === null
                        || $operation->heartbeat_at->lte(now()->subSeconds(self::LEASE_SECONDS))));
            if (! $recoverable) {
                return null;
            }

            if ($operation->automatic_claim_count >= self::MAX_AUTOMATIC_CLAIMS) {
                $operation->forceFill([
                    'status' => $this->terminalAfterExhaustion($operation),
                    'completed_at' => now(),
                    'retry_available_at' => now(),
                ])->save();

                return null;
            }

            $generation = $operation->attempt_generation + 1;
            $operation->forceFill([
                'status' => ExportOperationStatus::Processing,
                'attempt_generation' => $generation,
                'automatic_claim_count' => $operation->automatic_claim_count + 1,
                'started_at' => $operation->started_at ?? now(),
                'heartbeat_at' => now(),
                'completed_at' => null,
            ])->save();

            return [$operation->fresh('playlistExport'), $generation];
        });
    }

    private function fail(
        ExportOperation $operation,
        int $generation,
        ManagedExportFailureCode $code,
        bool $retryable,
        ?int $retryAfter = null,
    ): ?int {
        return DB::transaction(function () use ($operation, $generation, $code, $retryable, $retryAfter): ?int {
            $current = ExportOperation::query()
                ->whereKey($operation->getKey())
                ->where('attempt_generation', $generation)
                ->where('status', ExportOperationStatus::Processing->value)
                ->with('playlistExport.targetAttempts')
                ->lockForUpdate()
                ->first();

            if (! $current instanceof ExportOperation) {
                return null;
            }

            if ($code === ManagedExportFailureCode::TargetMissing) {
                $status = ExportOperationStatus::RecreateRequired;
            } elseif ($code === ManagedExportFailureCode::AmbiguousMutation && ! $retryable) {
                $status = ExportOperationStatus::ManualRecoveryRequired;
            } elseif ($retryable && $current->automatic_claim_count < self::MAX_AUTOMATIC_CLAIMS) {
                $delay = max(1, min(3600, $retryAfter ?? ($code === ManagedExportFailureCode::RateLimited ? 60 : 300)));
                $current->forceFill([
                    'status' => ExportOperationStatus::Queued,
                    'failure_code' => $code,
                    'heartbeat_at' => now(),
                    'retry_available_at' => now()->addSeconds($delay),
                ])->save();

                return $delay;
            } elseif ($this->hasUnknownTarget($current)) {
                $status = ExportOperationStatus::ManualRecoveryRequired;
            } elseif ($code === ManagedExportFailureCode::MetadataRejected && ! $this->hasLocator($current)) {
                $status = ExportOperationStatus::Failed;
            } elseif ($current->possible_mutation_at !== null || $this->hasLocator($current)) {
                $status = ExportOperationStatus::PartialFailed;
            } else {
                $status = ExportOperationStatus::Failed;
            }

            $current->forceFill([
                'status' => $status,
                'failure_code' => $code,
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'retry_available_at' => $status === ExportOperationStatus::Failed ? null : now(),
            ])->save();

            return null;
        });
    }

    private function terminalAfterExhaustion(ExportOperation $operation): ExportOperationStatus
    {
        $operation->loadMissing('playlistExport.targetAttempts');

        if ($this->hasUnknownTarget($operation)) {
            return ExportOperationStatus::ManualRecoveryRequired;
        }

        return $operation->possible_mutation_at !== null || $this->hasLocator($operation)
            ? ExportOperationStatus::PartialFailed
            : ExportOperationStatus::Failed;
    }

    private function hasUnknownTarget(ExportOperation $operation): bool
    {
        return $operation->playlistExport->targetAttempts
            ->contains(fn (PlaylistExportTargetAttempt $attempt): bool => $attempt->generation === $operation->playlistExport->target_generation
                && $attempt->status === PlaylistExportTargetAttempt::STATUS_UNKNOWN);
    }

    private function hasLocator(ExportOperation $operation): bool
    {
        return $operation->playlistExport->targetAttempts
            ->contains(fn (PlaylistExportTargetAttempt $attempt): bool => $attempt->generation === $operation->playlistExport->target_generation
                && $attempt->provider_playlist_id !== null);
    }

    /** @return list<string> */
    private function requiredScopes(StreamingProvider $provider): array
    {
        return match ($provider) {
            StreamingProvider::Spotify => SpotifyManagedPlaylistGateway::REQUIRED_SCOPES,
            StreamingProvider::YouTube => YouTubeManagedPlaylistGateway::REQUIRED_SCOPES,
        };
    }
}
