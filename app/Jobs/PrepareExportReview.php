<?php

namespace App\Jobs;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Data\MatchResult;
use App\Integrations\ExportMatching\Data\SourceTrack;
use App\Integrations\ExportMatching\MatchingFailure;
use App\Integrations\ExportMatching\Providers\SpotifyCatalogSearch;
use App\Integrations\ExportMatching\Providers\YouTubeCatalogSearch;
use App\Integrations\ExportMatching\YouTubeSearchBudget;
use App\Models\ExportReview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PrepareExportReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $exportReviewId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-review:{$this->exportReviewId}"))
                ->expireAfter(510)
                ->dontRelease(),
        ];
    }

    public function handle(
        SpotifyCatalogSearch $spotify,
        YouTubeCatalogSearch $youtube,
        YouTubeSearchBudget $budget,
        FingerprintPlaylistContent $fingerprint,
    ): void {
        $review = ExportReview::query()->with(['items', 'playlist.items', 'user'])->find($this->exportReviewId);

        if (! $review instanceof ExportReview) {
            return;
        }

        if ($review->playlist->role !== PlaylistRole::Source) {
            return;
        }

        if (in_array($review->status, [ExportReviewStatus::Ready, ExportReviewStatus::Failed], true)) {
            $this->queueNotificationIfLong($review);

            return;
        }

        if (! in_array($review->status, [ExportReviewStatus::Queued, ExportReviewStatus::Processing], true)) {
            return;
        }

        if ($review->expires_at->isPast()) {
            $this->terminal($review, ExportReviewStatus::Expired, null);

            return;
        }

        if (! hash_equals($review->source_fingerprint, $fingerprint->handle($review->playlist))) {
            $this->terminal($review, ExportReviewStatus::Failed, 'source-changed');

            return;
        }

        $review->forceFill([
            'status' => ExportReviewStatus::Processing,
            'started_at' => $review->started_at ?? now(),
        ])->save();

        $tracks = $review->items->mapWithKeys(fn ($item): array => [
            (int) $item->getKey() => SourceTrack::fromReviewItem($item),
        ]);
        $cacheKeys = $tracks->map(fn (SourceTrack $track): string => $this->cacheKey($review, $track));

        if ($review->target_provider === StreamingProvider::YouTube) {
            $misses = $cacheKeys->filter(fn (string $key): bool => $this->cachedResult($key) === null)->values()->all();
            if (! $budget->reserve($misses)) {
                $this->terminal($review, ExportReviewStatus::Failed, MatchingFailure::QuotaLimited->value);

                return;
            }
        }

        $results = [];
        foreach ($tracks as $itemId => $track) {
            $cacheKey = $cacheKeys[$itemId];
            $result = $this->cachedResult($cacheKey);

            if (! $result instanceof MatchResult) {
                $result = $review->target_provider === StreamingProvider::Spotify
                    ? $spotify->search($track, $review->target_provider, $review->target_market)
                    : $youtube->search($track, $review->target_provider, $review->target_market);

                if ($result instanceof MatchingFailure) {
                    $this->handleFailure($review, $result);

                    return;
                }

                Cache::put($cacheKey, $result->toArray(), now()->addDay());
            }

            $results[$itemId] = $result;
        }

        $publication = DB::transaction(function () use ($review, $results, $fingerprint): string {
            $current = ExportReview::query()->whereKey($review->getKey())->lockForUpdate()->first();
            if (! $current instanceof ExportReview
                || $current->status !== ExportReviewStatus::Processing) {
                return 'inactive';
            }

            if ($current->playlist->role !== PlaylistRole::Source) {
                return 'inactive';
            }

            if ($current->expires_at->isPast()) {
                return 'expired';
            }

            $current->playlist->load('items');
            if (! hash_equals($current->source_fingerprint, $fingerprint->handle($current->playlist))) {
                return 'source-changed';
            }

            foreach ($results as $itemId => $result) {
                $candidate = $result->candidate;
                $item = $current->items()->whereKey($itemId)->firstOrFail();
                $item->forceFill([
                    'match_status' => $result->status->value,
                    'target_catalog_id' => $candidate?->catalogId,
                    'target_catalog_uri' => $candidate?->catalogUri,
                    'target_title' => $candidate?->title,
                    'target_creators' => $candidate?->creators,
                    'target_album' => $candidate?->album,
                    'target_duration_milliseconds' => $candidate?->durationMilliseconds,
                ])->save();
            }

            $current->forceFill([
                'status' => ExportReviewStatus::Ready,
                'failure_code' => null,
                'completed_at' => now(),
            ])->save();

            return 'published';
        });

        if ($publication === 'published') {
            $this->queueNotificationIfLong($review->fresh());
        } elseif ($publication === 'expired') {
            $this->terminal($review, ExportReviewStatus::Expired, null);
        } elseif ($publication === 'source-changed') {
            $this->terminal($review, ExportReviewStatus::Failed, 'source-changed');
        }
    }

    private function handleFailure(ExportReview $review, MatchingFailure $failure): void
    {
        $retryable = in_array($failure, [MatchingFailure::RateLimited, MatchingFailure::TemporarilyUnavailable], true);
        if ($retryable && $this->attempts() < $this->tries && $review->expires_at->isFuture()) {
            $review->forceFill(['failure_code' => $failure->value])->save();
            $this->release($failure === MatchingFailure::RateLimited ? 60 : 300);

            return;
        }

        $this->terminal($review, ExportReviewStatus::Failed, $failure->value);
    }

    private function terminal(ExportReview $review, ExportReviewStatus $status, ?string $failure): void
    {
        $updated = ExportReview::query()
            ->whereKey($review->getKey())
            ->whereIn('status', [ExportReviewStatus::Queued->value, ExportReviewStatus::Processing->value])
            ->update([
                'status' => $status->value,
                'failure_code' => $failure,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 1) {
            $this->queueNotificationIfLong($review->fresh());
        }
    }

    private function queueNotificationIfLong(?ExportReview $review): void
    {
        if (! $review instanceof ExportReview || $review->started_at === null
            || $review->completed_at === null
            || $review->started_at->diffInSeconds($review->completed_at) < 60
            || $review->notification_sent_at !== null
            || ! in_array($review->status, [ExportReviewStatus::Ready, ExportReviewStatus::Failed], true)) {
            return;
        }

        SendExportReviewCompletedNotification::dispatch((int) $review->getKey());
    }

    private function cacheKey(ExportReview $review, SourceTrack $track): string
    {
        return 'export-matching:result:'.hash('sha256', implode('|', [
            $review->target_provider->value,
            $review->destination_type->value,
            hash('sha256', $review->target_account_id),
            $review->target_market ?? '-',
            $track->fingerprint(),
        ]));
    }

    private function cachedResult(string $key): ?MatchResult
    {
        $value = Cache::get($key);
        if (! is_array($value)) {
            return null;
        }

        try {
            return MatchResult::fromArray($value);
        } catch (Throwable) {
            Cache::forget($key);

            return null;
        }
    }
}
