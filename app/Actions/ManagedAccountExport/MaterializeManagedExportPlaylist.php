<?php

namespace App\Actions\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Enums\PlaylistOrigin;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Models\ExportOperation;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class MaterializeManagedExportPlaylist
{
    public function handle(
        string $operationId,
        int $generation,
        ManagedPlaylistMetadata $metadata,
        ManagedPlaylistSnapshot $snapshot,
    ): bool {
        try {
            return DB::transaction(function () use ($operationId, $generation, $metadata, $snapshot): bool {
                $operation = ExportOperation::query()
                    ->whereKey($operationId)
                    ->where('attempt_generation', $generation)
                    ->where('status', ExportOperationStatus::Processing->value)
                    ->with(['playlistExport', 'exportReview.items', 'user'])
                    ->lockForUpdate()
                    ->first();

                if (! $operation instanceof ExportOperation) {
                    return false;
                }

                $export = $operation->playlistExport;
                $export->refresh();
                if ($snapshot->reference->provider !== $export->target_provider
                    || ! hash_equals($snapshot->reference->ownerAccountId, $export->target_account_id)) {
                    return false;
                }

                $manifest = ConfirmedExportManifest::fromConfirmedReview($operation->exportReview);
                if (count($manifest->items) !== count($snapshot->items)) {
                    return false;
                }

                $reviewItems = $operation->exportReview->items->keyBy('position');
                foreach ($manifest->items as $manifestItem) {
                    if (! $reviewItems->has($manifestItem['position'])) {
                        return false;
                    }
                }

                if ($export->target_playlist_id !== null) {
                    $boundTarget = Playlist::query()->whereKey($export->target_playlist_id)->lockForUpdate()->first();
                    if (! $boundTarget instanceof Playlist
                        || $boundTarget->source_provider !== $export->target_provider
                        || ! hash_equals($boundTarget->source_playlist_id, $snapshot->reference->providerPlaylistId)
                        || $boundTarget->source_account_id === null
                        || ! hash_equals($boundTarget->source_account_id, $export->target_account_id)) {
                        return false;
                    }
                }

                $target = Playlist::query()
                    ->where('user_id', $operation->user_id)
                    ->where('source_provider', $export->target_provider->value)
                    ->where('source_playlist_id', $snapshot->reference->providerPlaylistId)
                    ->lockForUpdate()
                    ->first();

                if ($target instanceof Playlist) {
                    $ownedByThisExport = (string) $export->target_playlist_id === (string) $target->getKey();
                    $adoptableImport = $target->origin === PlaylistOrigin::Imported
                        && $target->source_account_id !== null
                        && hash_equals($target->source_account_id, $export->target_account_id)
                        && $target->managedExportTarget()->doesntExist();

                    if (! $ownedByThisExport && ! $adoptableImport) {
                        return false;
                    }
                    if ($export->target_playlist_id !== null
                        && (int) $export->target_playlist_id !== (int) $target->getKey()) {
                        return false;
                    }
                } else {
                    $target = $operation->user->playlists()->create([
                        'origin' => PlaylistOrigin::ManagedTarget,
                        'source_provider' => $export->target_provider,
                        'source_playlist_id' => $snapshot->reference->providerPlaylistId,
                        'source_account_id' => $export->target_account_id,
                        'canonical_source_url' => $snapshot->reference->canonicalUrl,
                        'provider_revision' => $snapshot->revision,
                        'name' => $metadata->title,
                        'description' => $metadata->description,
                        'provider_metadata_refreshed_at' => now(),
                        'imported_at' => now(),
                    ]);
                }

                $target->forceFill([
                    'origin' => PlaylistOrigin::ManagedTarget,
                    'streaming_account_id' => null,
                    'source_account_id' => $export->target_account_id,
                    'canonical_source_url' => $snapshot->reference->canonicalUrl,
                    'provider_revision' => $snapshot->revision,
                    'name' => $metadata->title,
                    'description' => $metadata->description,
                    'provider_metadata_refreshed_at' => now(),
                    'bank_content_edited_at' => null,
                ])->save();

                $target->items()->delete();
                $target->items()->createMany(array_map(function (array $manifestItem, int $position) use ($reviewItems, $snapshot): array {
                    $item = $reviewItems->get($manifestItem['position']);
                    if ($item === null) {
                        throw new \LogicException('Confirmed manifest item metadata is missing.');
                    }

                    return [
                        'position' => $position,
                        'occurrence_id' => $snapshot->items[$position]->occurrenceId,
                        'catalog_id' => $manifestItem['catalog_id'],
                        'catalog_uri' => $manifestItem['catalog_uri'],
                        'title' => $item->target_title,
                        'creators' => $item->target_creators ?? [],
                        'album' => $item->target_album,
                        'duration_milliseconds' => $item->target_duration_milliseconds,
                        'isrc' => null,
                        'is_available' => true,
                    ];
                }, $manifest->items, array_keys($manifest->items)));

                if ($export->target_playlist_id === null) {
                    $export->forceFill(['target_playlist_id' => $target->getKey()])->save();
                }

                $operation->forceFill([
                    'status' => ExportOperationStatus::Succeeded,
                    'failure_code' => null,
                    'completed_at' => now(),
                    'heartbeat_at' => now(),
                    'retry_available_at' => null,
                ])->save();

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }
}
