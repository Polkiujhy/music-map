<?php

namespace App\Integrations\PlaylistSync\Providers;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Contracts\SourcePlaylistWriter;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\YouTube\PlanYouTubePlaylistMutations;
use App\Integrations\PlaylistSync\YouTube\YouTubePlaylistMutation;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\PlaylistSyncRun;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class YouTubeSourcePlaylistWriter implements SourcePlaylistWriter
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    public function __construct(
        private YouTubeSourcePlaylistReader $reader,
        private PlanYouTubePlaylistMutations $planner,
    ) {}

    public function provider(): StreamingProvider
    {
        return StreamingProvider::YouTube;
    }

    public function write(string $providerPlaylistId, SourcePlaylistSnapshot $current, array $desiredItems, StreamingAccessContext $access, ?PlaylistSyncRun $run = null): SourcePlaylistSnapshot|SourceSyncFailure
    {
        if ($access->provider !== StreamingProvider::YouTube || $providerPlaylistId === '' || $run === null || count($desiredItems) > 20) {
            return SourceSyncFailure::InvalidResponse;
        }
        $desired = array_map(static fn (array $item): string => (string) ($item['catalog_id'] ?? ''), $desiredItems);
        if (in_array('', $desired, true)) {
            return SourceSyncFailure::InvalidResponse;
        }

        try {
            $checkpoint = is_array($run->checkpoint) ? $run->checkpoint : null;
            if ($checkpoint === null) {
                $mutations = $this->planner->handle($current, $desired);
                $checkpoint = ['index' => 0, 'mutations' => array_map(fn ($mutation) => $mutation->toArray(), $mutations)];
                $run->update(['checkpoint' => $checkpoint]);
            }
            $mutations = array_map(
                static fn (array $mutation): YouTubePlaylistMutation => YouTubePlaylistMutation::fromArray($mutation),
                $checkpoint['mutations'] ?? [],
            );
            $index = (int) ($checkpoint['index'] ?? 0);
            $snapshot = $current;

            while ($index < count($mutations)) {
                $mutation = $mutations[$index];
                $fingerprint = PlanYouTubePlaylistMutations::fingerprintIdentifiers($snapshot->normalizedItemIdentifiers());
                $desiredFingerprint = PlanYouTubePlaylistMutations::fingerprintIdentifiers($desired);
                if (hash_equals($desiredFingerprint, $fingerprint)) {
                    break;
                }
                if (hash_equals($mutation->afterFingerprint, $fingerprint)) {
                    $index++;
                    $run->update(['checkpoint' => ['index' => $index, 'mutations' => $checkpoint['mutations']]]);

                    continue;
                }
                if (! hash_equals($mutation->beforeFingerprint, $fingerprint)) {
                    return SourceSyncFailure::ExternalDrift;
                }

                $failure = $this->mutate($providerPlaylistId, $mutation, $access);
                if ($failure !== null) {
                    return $failure;
                }
                $confirmed = $this->reader->read($providerPlaylistId, $access);
                if (! $confirmed instanceof SourcePlaylistSnapshot) {
                    return $confirmed;
                }
                $snapshot = $confirmed;
                $after = PlanYouTubePlaylistMutations::fingerprintIdentifiers($snapshot->normalizedItemIdentifiers());
                if (! hash_equals($mutation->afterFingerprint, $after)
                    && ! hash_equals($desiredFingerprint, $after)) {
                    return SourceSyncFailure::ExternalDrift;
                }
                $index++;
                $run->update(['checkpoint' => ['index' => $index, 'mutations' => $checkpoint['mutations']]]);
            }

            return $snapshot;
        } catch (Throwable) {
            return SourceSyncFailure::ProviderUnavailable;
        }
    }

    private function mutate(string $playlistId, YouTubePlaylistMutation $mutation, StreamingAccessContext $access): ?SourceSyncFailure
    {
        $request = Http::withToken($access->accessToken)->acceptJson()->connectTimeout(5)->timeout(10);
        $response = match ($mutation->type) {
            YouTubePlaylistMutation::DELETE => $request->withQueryParameters(['id' => $mutation->providerItemId])
                ->delete(self::API_URL.'/playlistItems'),
            YouTubePlaylistMutation::INSERT => $request->post(self::API_URL.'/playlistItems?part=snippet', [
                'snippet' => ['playlistId' => $playlistId, 'position' => $mutation->position,
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $mutation->catalogId]],
            ]),
            YouTubePlaylistMutation::UPDATE_POSITION => $request->put(self::API_URL.'/playlistItems?part=snippet', [
                'id' => $mutation->providerItemId,
                'snippet' => ['playlistId' => $playlistId, 'position' => $mutation->position,
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $mutation->catalogId]],
            ]),
        };

        return $response->successful() ? null : $this->failure($response);
    }

    private function failure(Response $response): SourceSyncFailure
    {
        return match (true) {
            $response->status() === 401 => SourceSyncFailure::Unauthorized,
            $response->status() === 403 => SourceSyncFailure::Forbidden,
            $response->status() === 404 => SourceSyncFailure::NotFound,
            $response->status() === 429 => SourceSyncFailure::RateLimited,
            $response->status() >= 500 => SourceSyncFailure::ProviderUnavailable,
            default => SourceSyncFailure::InvalidResponse,
        };
    }
}
