<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\ExportReviews\FingerprintPlaylist;
use App\Actions\ExportReviews\ResolvedExportDestination;
use App\Actions\ExportReviews\StartExportReview;
use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Providers\SpotifyCatalogSearch;
use App\Integrations\ExportMatching\Providers\YouTubeCatalogSearch;
use App\Integrations\ExportMatching\YouTubeSearchBudget;
use App\Jobs\PrepareExportReview;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Notifications\ExportReviewCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrepareExportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        Cache::flush();
        config([
            'cache.default' => 'array',
            'services.export_matching.spotify.client_id' => 'client-canary',
            'services.export_matching.spotify.client_secret' => 'secret-canary',
            'services.export_matching.youtube.api_key' => 'youtube-key-canary',
            'services.export_matching.youtube.daily_search_limit' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_start_is_idempotent_for_the_same_locked_snapshot_and_dispatches_after_commit_once(): void
    {
        Queue::fake();
        $playlist = $this->playlist(1);
        $destination = $this->destination();

        $first = app(StartExportReview::class)->handle($playlist->user, $playlist, $destination);
        $second = app(StartExportReview::class)->handle($playlist->user, $playlist->fresh(), $destination);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('export_reviews', 1);
        $this->assertDatabaseCount('export_review_items', 1);
        Queue::assertPushed(PrepareExportReview::class, 1);
    }

    public function test_job_publishes_every_result_atomically_only_after_full_success(): void
    {
        $review = $this->review(2);
        Http::fakeSequence()->push(['access_token' => 'token'])->push($this->spotifyPayload('one'))->push($this->spotifyPayload('two'));

        $this->runJob($review);

        $review->refresh();
        $this->assertSame(ExportReviewStatus::Ready, $review->status);
        $this->assertNotNull($review->completed_at);
        $this->assertSame(
            ['spotify-one', 'spotify-two'],
            $review->items()->pluck('target_catalog_id')->all(),
        );
        $this->assertTrue($review->items()->get()->every(fn ($item): bool => $item->match_status === ExportMatchStatus::Matched));
    }

    public function test_job_handles_empty_and_twenty_item_fixtures_with_bounded_fake_catalog_calls(): void
    {
        $emptyReview = $this->review(0);

        $this->runJob($emptyReview);

        $this->assertSame(ExportReviewStatus::Ready, $emptyReview->fresh()->status);
        $this->assertSame(0, $emptyReview->items()->count());
        Http::assertNothingSent();

        $fullReview = $this->review(20);
        Http::fake(fn (Request $request): mixed => $request->url() === 'https://accounts.spotify.com/api/token'
            ? Http::response(['access_token' => 'token'])
            : Http::response(['tracks' => ['items' => []]]));

        $this->runJob($fullReview);

        $fullReview->refresh();
        $this->assertSame(ExportReviewStatus::Ready, $fullReview->status, (string) $fullReview->failure_code);
        $this->assertSame(20, $fullReview->items()->count());
        $this->assertSame(20, $fullReview->items()->where('match_status', ExportMatchStatus::Unavailable->value)->count());
        Http::assertSentCount(21);
    }

    public function test_operational_failure_never_publishes_partial_item_state(): void
    {
        $review = $this->review(2);
        Http::fakeSequence()
            ->push(['access_token' => 'token'])
            ->push($this->spotifyPayload('one'))
            ->push(['tracks' => ['items' => [['id' => 'missing-required-fields']]]]);

        $this->runJob($review);

        $review->refresh();
        $this->assertSame(ExportReviewStatus::Failed, $review->status);
        $this->assertSame('invalid-response', $review->failure_code);
        $this->assertSame([null, null], $review->items()->pluck('target_catalog_id')->all());
    }

    public function test_valid_empty_catalog_response_is_unavailable_not_an_operational_failure(): void
    {
        $review = $this->review(1);
        Http::fakeSequence()->push(['access_token' => 'token'])->push(['tracks' => ['items' => []]]);

        $this->runJob($review);

        $review->refresh();
        $this->assertSame(ExportReviewStatus::Ready, $review->status);
        $this->assertNull($review->failure_code);
        $this->assertSame(ExportMatchStatus::Unavailable, $review->items()->firstOrFail()->match_status);
    }

    public function test_retryable_failure_keeps_review_processing_and_reuses_fresh_cache(): void
    {
        $review = $this->review(2);
        $catalogCalls = 0;
        Http::fake(function (Request $request) use (&$catalogCalls): mixed {
            if ($request->url() === 'https://accounts.spotify.com/api/token') {
                return Http::response(['access_token' => 'token']);
            }

            $catalogCalls++;

            return match ($catalogCalls) {
                1 => Http::response($this->spotifyPayload('one')),
                2 => Http::response([], 429),
                3 => Http::response($this->spotifyPayload('two')),
                default => Http::response(['unexpected_request' => true], 500),
            };
        });

        $this->runJob($review);

        $review->refresh();
        $this->assertSame(ExportReviewStatus::Processing, $review->status);
        $this->assertSame('rate-limited', $review->failure_code);
        $this->assertSame([null, null], $review->items()->pluck('target_catalog_id')->all());

        $this->runJob($review);

        $this->assertSame(ExportReviewStatus::Ready, $review->fresh()->status);
        $this->assertSame(3, $catalogCalls, 'The successful first result must come from cache on retry.');
    }

    public function test_late_job_cannot_overwrite_a_terminal_review_or_changed_snapshot(): void
    {
        $review = $this->review(1);
        $review->update(['status' => ExportReviewStatus::Confirmed, 'confirmed_at' => now()]);
        Http::fake();

        $this->runJob($review);

        $this->assertSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
        Http::assertNothingSent();

        $changed = $this->review(1);
        $changed->playlist->items()->firstOrFail()->update(['title' => 'Changed after queueing']);
        $this->runJob($changed);

        $this->assertSame(ExportReviewStatus::Failed, $changed->fresh()->status);
        $this->assertSame('source-changed', $changed->fresh()->failure_code);
        Http::assertNothingSent();
    }

    public function test_notification_is_suppressed_below_sixty_seconds(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        $review = $this->review(1);
        Carbon::setTestNow('2026-09-14 12:00:59');
        Http::fakeSequence()->push(['access_token' => 'token'])->push($this->spotifyPayload('one'));

        $this->runJob($review);

        Notification::assertNothingSent();
        $this->assertNull($review->fresh()->notification_sent_at);
    }

    public function test_notification_is_queued_once_after_sixty_seconds_even_if_job_runs_again(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        $review = $this->review(1);
        Carbon::setTestNow('2026-09-14 12:01:00');
        Http::fakeSequence()->push(['access_token' => 'token'])->push($this->spotifyPayload('one'));

        $this->runJob($review);
        $this->runJob($review);

        Notification::assertSentToTimes($review->user, ExportReviewCompleted::class, 1);
        $this->assertNotNull($review->fresh()->notification_sent_at);
    }

    public function test_quota_failure_does_not_search_or_retry_and_notifies_after_the_threshold(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');
        config(['services.export_matching.youtube.daily_search_limit' => 0]);
        $review = $this->review(1, $this->destination(StreamingProvider::YouTube));
        Carbon::setTestNow('2026-09-14 12:01:00');
        Http::fake();

        $this->runJob($review);

        $review->refresh();
        $this->assertSame(ExportReviewStatus::Failed, $review->status);
        $this->assertSame('quota-limited', $review->failure_code);
        Http::assertNothingSent();
        Notification::assertSentToTimes($review->user, ExportReviewCompleted::class, 1);
    }

    private function runJob(ExportReview $review): void
    {
        (new PrepareExportReview((int) $review->getKey()))->handle(
            app(SpotifyCatalogSearch::class),
            app(YouTubeCatalogSearch::class),
            app(YouTubeSearchBudget::class),
            app(FingerprintPlaylist::class),
        );
    }

    private function review(int $items, ?ResolvedExportDestination $destination = null): ExportReview
    {
        Queue::fake();
        $playlist = $this->playlist($items);

        return app(StartExportReview::class)->handle($playlist->user, $playlist, $destination ?? $this->destination());
    }

    private function playlist(int $items): Playlist
    {
        $playlist = Playlist::factory()->create();
        foreach ($items === 0 ? [] : range(0, $items - 1) as $position) {
            PlaylistItem::factory()->for($playlist)->create([
                'position' => $position,
                'title' => 'Canary Song '.$position,
                'creators' => ['Canary Artist'],
                'duration_milliseconds' => 180000,
                'isrc' => null,
                'is_available' => true,
            ]);
        }

        return $playlist->load(['user', 'items']);
    }

    private function destination(StreamingProvider $provider = StreamingProvider::Spotify): ResolvedExportDestination
    {
        return new ResolvedExportDestination(
            $provider,
            ExportDestinationType::Managed,
            null,
            'managed-canary',
            $provider === StreamingProvider::Spotify ? 'GB' : null,
        );
    }

    private function spotifyPayload(string $suffix): array
    {
        $position = $suffix === 'one' ? 0 : 1;

        return ['tracks' => ['items' => [[
            'id' => 'spotify-'.$suffix,
            'uri' => 'spotify:track:'.$suffix,
            'name' => 'Canary Song '.$position,
            'artists' => [['name' => 'Canary Artist']],
            'album' => ['name' => 'Canary Album'],
            'duration_ms' => 180000,
            'external_ids' => [],
        ]]]];
    }
}
