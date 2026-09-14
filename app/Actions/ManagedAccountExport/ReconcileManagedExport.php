<?php

namespace App\Actions\ManagedAccountExport;

use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Integrations\ManagedAccountExport\Contracts\ManagedPlaylistGateway;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistMetadataFactory;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Models\ExportOperation;
use App\Models\PlaylistExportTargetAttempt;

final readonly class ReconcileManagedExport
{
    public function __construct(
        private ResolveManagedExportTarget $targets,
        private ManagedPlaylistMetadataFactory $metadata,
        private MaterializeManagedExportPlaylist $materialize,
    ) {}

    public function handle(
        ExportOperation $operation,
        int $generation,
        ManagedAccessContext $access,
        ManagedPlaylistGateway $gateway,
    ): ManagedProviderFailure|ManagedExportFailureCode|null {
        $operation->loadMissing(['playlistExport.targetAttempts', 'exportReview.items', 'playlistExport.sourcePlaylist']);
        $export = $operation->playlistExport;
        $attempt = $export->targetAttempts
            ->first(fn (PlaylistExportTargetAttempt $candidate): bool => $candidate->generation === $export->target_generation
                && $candidate->status !== PlaylistExportTargetAttempt::STATUS_ABANDONED);
        if (! $attempt instanceof PlaylistExportTargetAttempt) {
            return ManagedExportFailureCode::PersistenceFailure;
        }

        $metadata = $this->metadata->make(
            $export->target_provider,
            $operation->playlist_name ?? 'Playlista',
            $operation->playlist_description,
            $attempt->marker,
        );
        $reference = $this->targets->handle($operation, $generation, $access, $gateway, $metadata);
        if ($reference instanceof ManagedProviderFailure || $reference instanceof ManagedExportFailureCode) {
            return $reference;
        }

        if (($failure = $access->mutationFailure()) !== null) {
            return $failure;
        }

        $manifest = ConfirmedExportManifest::fromConfirmedReview($operation->exportReview);
        $catalogItems = array_map(
            fn (array $item): string => $export->target_provider->value === 'spotify'
                ? $item['catalog_uri']
                : $item['catalog_id'],
            $manifest->items,
        );
        $result = $gateway->reconcile($access, $reference, $metadata, $catalogItems);
        if ($result instanceof ManagedProviderFailure) {
            return $result;
        }
        if (! $result->exact || $result->snapshot === null) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse, true, 300);
        }

        $actualItems = array_map(
            fn ($item): string => $export->target_provider->value === 'spotify' ? $item->catalogUri : $item->catalogId,
            $result->snapshot->items,
        );
        if ($actualItems !== array_values($catalogItems)
            || $result->snapshot->metadata->title !== $metadata->title
            || $result->snapshot->metadata->description !== $metadata->description
            || $result->snapshot->metadata->marker !== $metadata->marker) {
            return new ManagedProviderFailure(ManagedExportFailureCode::InvalidResponse, true, 300);
        }

        return $this->materialize->handle($operation->getKey(), $generation, $metadata, $result->snapshot)
            ? null
            : ManagedExportFailureCode::PersistenceFailure;
    }
}
