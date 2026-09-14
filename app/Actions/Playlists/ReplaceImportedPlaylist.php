<?php

namespace App\Actions\Playlists;

use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistItemSnapshot;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Models\Playlist;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class ReplaceImportedPlaylist
{
    public function handle(
        User $user,
        PlaylistSnapshot $snapshot,
        bool $markImported = true,
        ?int $streamingAccountId = null,
    ): Playlist|ImportFailureCode {
        return DB::transaction(function () use ($user, $snapshot, $markImported, $streamingAccountId): Playlist|ImportFailureCode {
            // Serialize bank insertion with the export target locator checkpoint.
            // Both operations know the user even before a local target row exists.
            User::query()->whereKey($user->getKey())->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            if ($this->isKnownExportTarget($user, $snapshot->provider, $snapshot->providerPlaylistId)) {
                return ImportFailureCode::ExportTargetConflict;
            }

            $identity = [
                'user_id' => $user->getKey(),
                'source_provider' => $snapshot->provider->value,
                'source_playlist_id' => $snapshot->providerPlaylistId,
            ];

            $existing = $user->playlists()
                ->where('source_provider', $snapshot->provider->value)
                ->where('source_playlist_id', $snapshot->providerPlaylistId)
                ->lockForUpdate()
                ->first();

            if ($existing?->isExportTarget()) {
                return ImportFailureCode::ExportTargetConflict;
            }

            DB::table('playlists')->insertOrIgnore(
                [
                    ...$identity,
                    'origin' => PlaylistOrigin::Imported->value,
                    ...$this->playlistAttributes($snapshot, now(), $streamingAccountId),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $playlist = $user->playlists()
                ->where('source_provider', $snapshot->provider->value)
                ->where('source_playlist_id', $snapshot->providerPlaylistId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($playlist->isExportTarget()) {
                return ImportFailureCode::ExportTargetConflict;
            }

            $playlist->update($this->playlistAttributes(
                $snapshot,
                $markImported ? now() : $playlist->imported_at,
                $streamingAccountId,
            ));
            $playlist->items()->delete();
            $playlist->items()->createMany(array_map(
                fn (PlaylistItemSnapshot $item): array => [
                    'position' => $item->position,
                    'occurrence_id' => $item->occurrenceId,
                    'catalog_id' => $item->catalogId,
                    'catalog_uri' => $item->catalogUri,
                    'title' => $item->title,
                    'creators' => $item->creators,
                    'album' => $item->album,
                    'duration_milliseconds' => $item->durationMilliseconds,
                    'isrc' => $item->isrc,
                    'is_available' => $item->isAvailable,
                ],
                $snapshot->items,
            ));

            return $playlist->refresh()->load('items');
        });
    }

    public function isKnownExportTarget(User $user, StreamingProvider $provider, string $providerPlaylistId): bool
    {
        return $user->playlists()
            ->where('origin', PlaylistOrigin::ManagedTarget->value)
            ->where('source_provider', $provider->value)
            ->where('source_playlist_id', $providerPlaylistId)
            ->exists()
            || PlaylistExportTargetAttempt::query()
                ->where('target_provider', $provider->value)
                ->where('provider_playlist_id', $providerPlaylistId)
                ->whereHas('playlistExport.sourcePlaylist', fn ($query) => $query->where('user_id', $user->getKey()))
                ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function playlistAttributes(
        PlaylistSnapshot $snapshot,
        DateTimeInterface $importedAt,
        ?int $streamingAccountId,
    ): array {
        return [
            'source_account_id' => $snapshot->sourceAccountId,
            'streaming_account_id' => $streamingAccountId,
            'canonical_source_url' => $snapshot->canonicalUrl,
            'provider_revision' => $snapshot->providerRevision,
            'name' => $snapshot->name,
            'description' => $snapshot->description,
            'provider_metadata_refreshed_at' => $snapshot->providerMetadataRefreshedAt,
            'imported_at' => $importedAt,
        ];
    }
}
