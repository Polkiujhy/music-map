<?php

namespace App\Jobs;

use App\Actions\Playlists\RefreshYouTubePlaylistMetadata as RefreshMetadata;
use App\Integrations\PlaylistImport\ImportFailureCode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshYouTubePlaylistMetadata implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $playlistId,
    ) {}

    public function handle(RefreshMetadata $refresh): void
    {
        $result = $refresh->handle($this->playlistId);

        if (! $result instanceof ImportFailureCode) {
            return;
        }

        Log::warning('youtube_playlist_metadata_refresh_failed', [
            'playlist_id' => $this->playlistId,
            'failure_code' => $result->value,
        ]);

        if (in_array($result, [ImportFailureCode::RateLimited, ImportFailureCode::QuotaLimited], true)
            && $this->attempts() < $this->tries) {
            $this->release($result === ImportFailureCode::RateLimited ? 60 : 300);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->playlistId;
    }
}
