<?php

namespace App\Actions\Exports;

use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\PlaylistRole;
use App\Integrations\PlaylistExport\Data\PlaylistWriteResult;
use App\Integrations\PlaylistExport\ProviderPlaylistUrl;
use App\Models\ExportOperation;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class PersistExportTarget
{
    public function checkpoint(ExportOperation $operation, string $providerId): PlaylistExportLink
    {
        $canonicalUrl = ProviderPlaylistUrl::fromId($operation->target_provider, $providerId);
        if ($canonicalUrl === null) {
            throw new LogicException('The provider returned an invalid playlist ID.');
        }

        return DB::transaction(function () use ($operation, $providerId, $canonicalUrl): PlaylistExportLink {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->playlist_export_link_id !== null) {
                return $locked->playlistExportLink()->firstOrFail();
            }

            $activeKey = PlaylistExportLink::activeKey(
                (int) $locked->source_playlist_id,
                $locked->target_provider,
                $locked->target_account_id,
            );
            $existing = PlaylistExportLink::query()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PlaylistExportLink) {
                if ($existing->targetPlaylist()->value('source_playlist_id') !== $providerId) {
                    throw new LogicException('The active export target does not match the provider checkpoint.');
                }

                $locked->playlistExportLink()->associate($existing);
                $locked->save();

                return $existing;
            }

            $collision = Playlist::query()
                ->where('user_id', $locked->user_id)
                ->where('source_provider', $locked->target_provider->value)
                ->where('source_playlist_id', $providerId)
                ->lockForUpdate()
                ->first();
            if ($collision instanceof Playlist) {
                throw new LogicException('The provider playlist ID is already represented by another local playlist.');
            }

            $target = new Playlist([
                'role' => PlaylistRole::ExportTarget,
                'source_provider' => $locked->target_provider,
                'source_playlist_id' => $providerId,
                'source_account_id' => $locked->target_account_id,
                'canonical_source_url' => $canonicalUrl,
                'provider_revision' => null,
                'name' => $locked->playlist_name,
                'description' => $locked->playlist_description,
                'provider_metadata_refreshed_at' => now(),
                'imported_at' => now(),
            ]);
            $target->user()->associate($locked->user_id);
            $target->save();

            $link = new PlaylistExportLink([
                'provider' => $locked->target_provider,
                'destination_type' => $locked->destination_type,
                'target_account_id' => $locked->target_account_id,
                'active_key' => $activeKey,
            ]);
            $link->user()->associate($locked->user_id);
            $link->sourcePlaylist()->associate($locked->source_playlist_id);
            $link->targetPlaylist()->associate($target);
            $link->save();

            $locked->playlistExportLink()->associate($link);
            $locked->save();

            return $link;
        });
    }

    public function complete(ExportOperation $operation, PlaylistWriteResult $result): void
    {
        if (! $result->succeeded() || $result->providerId === null) {
            throw new LogicException('Only a successful provider result can complete an export.');
        }

        DB::transaction(function () use ($operation, $result): void {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ExportOperationStatus::Transferred) {
                return;
            }
            if ($locked->status !== ExportOperationStatus::Processing) {
                throw new LogicException('Only a processing export can be completed.');
            }

            $link = $locked->playlistExportLink()->lockForUpdate()->firstOrFail();
            $target = $link->targetPlaylist()->lockForUpdate()->firstOrFail();
            if ($target->source_playlist_id !== $result->providerId) {
                throw new LogicException('The provider result changed the persisted export target ID.');
            }

            $items = $this->manifestItems($locked);
            $expected = $locked->target_provider->value === 'spotify'
                ? $items->pluck('target_catalog_uri')->all()
                : $items->pluck('target_catalog_id')->all();
            if ($expected !== $result->items) {
                throw new LogicException('The provider result does not match the frozen manifest.');
            }

            $target->forceFill([
                'provider_revision' => $result->revision,
                'name' => $locked->playlist_name,
                'description' => $locked->playlist_description,
                'provider_metadata_refreshed_at' => now(),
            ])->save();
            $target->items()->delete();
            $target->items()->createMany($items->values()->map(
                fn (ExportReviewItem $item, int $position): array => [
                    'position' => $position,
                    'occurrence_id' => null,
                    'catalog_id' => $item->target_catalog_id,
                    'catalog_uri' => $item->target_catalog_uri,
                    'title' => $item->target_title,
                    'creators' => $item->target_creators ?? [],
                    'album' => $item->target_album,
                    'duration_milliseconds' => $item->target_duration_milliseconds,
                    'isrc' => null,
                    'is_available' => true,
                ],
            )->all());

            $locked->forceFill([
                'status' => ExportOperationStatus::Transferred,
                'failure_code' => null,
                'active_key' => null,
                'completed_at' => now(),
            ])->save();
        });
    }

    public function retireDeletedTarget(ExportOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $locked = ExportOperation::query()->whereKey($operation->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return;
            }

            $link = $locked->playlistExportLink()->lockForUpdate()->first();
            if ($link instanceof PlaylistExportLink && $link->retired_at === null) {
                $link->forceFill(['retired_at' => now(), 'active_key' => null])->save();
            }

            $locked->forceFill([
                'status' => ExportOperationStatus::Failed,
                'failure_code' => ExportOperationFailure::TargetDeleted,
                'active_key' => null,
                'completed_at' => now(),
            ])->save();
        });
    }

    /** @return Collection<int, ExportReviewItem> */
    private function manifestItems(ExportOperation $operation): Collection
    {
        return $operation->exportReview->items
            ->filter(fn (ExportReviewItem $item): bool => $item->match_status !== ExportMatchStatus::Unavailable
                && ($item->decision ?? ExportReviewDecision::Keep) === ExportReviewDecision::Keep)
            ->values();
    }
}
