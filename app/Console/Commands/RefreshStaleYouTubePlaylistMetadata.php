<?php

namespace App\Console\Commands;

use App\Enums\StreamingProvider;
use App\Jobs\RefreshYouTubePlaylistMetadata;
use App\Models\Playlist;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshStaleYouTubePlaylistMetadata extends Command
{
    protected $signature = 'playlists:refresh-youtube-metadata';

    protected $description = 'Refresh aging YouTube playlist metadata and purge expired provider data';

    public function handle(): int
    {
        $refreshBefore = now()->subDays(28);
        $expireBefore = now()->subDays(30);

        Playlist::query()
            ->sourceOnly()
            ->where('source_provider', StreamingProvider::YouTube->value)
            ->where('provider_metadata_refreshed_at', '<=', $expireBefore)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('source_account_id')
                    ->orWhereNotNull('provider_revision')
                    ->orWhereNotNull('name')
                    ->orWhereNotNull('description')
                    ->orWhereHas('items');
            })
            ->chunkById(100, function ($playlists) use ($expireBefore): void {
                $playlists->each(fn (Playlist $playlist) => $this->purgeExpiredMetadata(
                    $playlist->getKey(),
                    $expireBefore,
                ));
            });

        Playlist::query()
            ->sourceOnly()
            ->where('source_provider', StreamingProvider::YouTube->value)
            ->where('provider_metadata_refreshed_at', '>', $expireBefore)
            ->where('provider_metadata_refreshed_at', '<=', $refreshBefore)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(fn (Playlist $playlist) => RefreshYouTubePlaylistMetadata::dispatch($playlist->getKey()));

        return self::SUCCESS;
    }

    private function purgeExpiredMetadata(int $playlistId, CarbonInterface $expireBefore): void
    {
        DB::transaction(function () use ($playlistId, $expireBefore): void {
            $playlist = Playlist::query()
                ->sourceOnly()
                ->whereKey($playlistId)
                ->where('source_provider', StreamingProvider::YouTube->value)
                ->lockForUpdate()
                ->first();

            if ($playlist === null
                || $playlist->provider_metadata_refreshed_at->gt($expireBefore)) {
                return;
            }

            $playlist->items()->delete();
            $playlist->update([
                'source_account_id' => null,
                'provider_revision' => null,
                'name' => null,
                'description' => null,
                'bank_content_edited_at' => null,
            ]);
        });
    }
}
