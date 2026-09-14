<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\ExportReviews\FingerprintPlaylist;
use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportReviewRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
            'services.platform_access.youtube.technical.account_id' => 'managed-youtube',
        ]);
    }

    public function test_routes_have_stable_methods_uris_and_security_middleware(): void
    {
        $expectations = [
            'export-reviews.store' => [['POST'], 'bank/playlists/{playlist}/export-reviews', 'throttle:export-review-start'],
            'export-reviews.show' => [['GET', 'HEAD'], 'bank/playlists/{playlist}/export-reviews/{exportReview}', null],
            'export-reviews.retry' => [['POST'], 'bank/playlists/{playlist}/export-reviews/{exportReview}/retry', 'throttle:export-review-retry'],
            'export-reviews.confirm' => [['POST'], 'bank/playlists/{playlist}/export-reviews/{exportReview}/confirm', null],
        ];

        foreach ($expectations as $name => [$methods, $uri, $throttle]) {
            $route = $this->route($name);
            $this->assertSame($methods, $route->methods());
            $this->assertSame($uri, $route->uri());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            if ($throttle !== null) {
                $this->assertContains($throttle, $route->gatherMiddleware());
            }
        }
    }

    public function test_guest_and_unverified_users_cannot_access_review_routes(): void
    {
        $review = $this->review();
        $urls = [
            ['post', route('export-reviews.store', $review->playlist), $this->startPayload()],
            ['get', route('export-reviews.show', [$review->playlist, $review]), []],
            ['post', route('export-reviews.retry', [$review->playlist, $review]), []],
            ['post', route('export-reviews.confirm', [$review->playlist, $review]), ['decisions' => []]],
        ];

        foreach ($urls as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertRedirect(route('login'));
        }

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified);
        foreach ($urls as [$method, $url, $payload]) {
            $this->{$method}($url, $payload)->assertRedirect(route('verification.notice'));
        }
    }

    public function test_every_lookup_is_owner_and_playlist_scoped(): void
    {
        Queue::fake();
        $ownerReview = $this->review();
        $other = User::factory()->create();
        $otherPlaylist = Playlist::factory()->for($other)->create();

        $this->actingAs($other)
            ->post(route('export-reviews.store', $ownerReview->playlist), $this->startPayload())
            ->assertNotFound();
        $this->get(route('export-reviews.show', [$ownerReview->playlist, $ownerReview]))->assertNotFound();
        $this->post(route('export-reviews.retry', [$ownerReview->playlist, $ownerReview]))->assertNotFound();
        $this->post(route('export-reviews.confirm', [$ownerReview->playlist, $ownerReview]), ['decisions' => []])->assertNotFound();

        $this->actingAs($ownerReview->user)
            ->get(route('export-reviews.show', [$otherPlaylist, $ownerReview]))
            ->assertNotFound();
    }

    public function test_mutating_routes_reject_requests_without_a_csrf_token(): void
    {
        $review = $this->review(ExportReviewStatus::Failed);
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $middleware);
        $this->actingAs($review->user);

        $this->post(route('export-reviews.store', $review->playlist), $this->startPayload())->assertStatus(419);
        $this->post(route('export-reviews.retry', [$review->playlist, $review]))->assertStatus(419);
        $this->post(route('export-reviews.confirm', [$review->playlist, $review]), ['decisions' => []])->assertStatus(419);
    }

    public function test_start_is_throttled_per_user_and_provider(): void
    {
        Queue::fake();
        $playlist = $this->playlist();
        $this->actingAs($playlist->user);

        foreach (range(1, 5) as $_) {
            $this->post(route('export-reviews.store', $playlist), $this->startPayload())->assertRedirect();
        }

        $this->post(route('export-reviews.store', $playlist), $this->startPayload())->assertTooManyRequests();
    }

    public function test_retry_is_throttled_per_user_and_review_provider(): void
    {
        Queue::fake();
        $review = $this->review(ExportReviewStatus::Failed);
        $this->actingAs($review->user);

        foreach (range(1, 3) as $_) {
            $this->post(route('export-reviews.retry', [$review->playlist, $review]))->assertRedirect();
        }

        $this->post(route('export-reviews.retry', [$review->playlist, $review]))->assertTooManyRequests();
    }

    public function test_backend_rejects_a_source_and_target_on_the_same_provider_account(): void
    {
        Queue::fake();
        config(['services.platform_access.youtube.technical.account_id' => 'same-account']);
        $playlist = $this->playlist([
            'source_provider' => StreamingProvider::YouTube,
            'source_account_id' => 'same-account',
        ]);

        $this->actingAs($playlist->user)
            ->from(route('bank.index'))
            ->post(route('export-reviews.store', $playlist), [
                'target_provider' => 'youtube',
                'destination_type' => 'managed',
            ])
            ->assertRedirect(route('bank.index'))
            ->assertSessionHasErrors('destination');

        $this->assertDatabaseCount('export_reviews', 0);
    }

    public function test_bank_loads_only_latest_actionable_reviews_for_current_targets(): void
    {
        $playlist = $this->playlist();
        $create = fn (array $attributes): ExportReview => ExportReview::factory()
            ->for($playlist->user)
            ->for($playlist)
            ->create($attributes);

        $create(['status' => ExportReviewStatus::Ready, 'target_account_id' => 'managed-spotify']);
        $spotifyActive = $create(['status' => ExportReviewStatus::Ready, 'target_account_id' => 'managed-spotify']);
        $create(['status' => ExportReviewStatus::Failed, 'target_account_id' => 'managed-spotify']);
        $spotifyRetryable = $create(['status' => ExportReviewStatus::Failed, 'target_account_id' => 'managed-spotify']);
        $youtubeActive = $create([
            'target_provider' => StreamingProvider::YouTube,
            'target_market' => null,
            'status' => ExportReviewStatus::Processing,
            'target_account_id' => 'managed-youtube',
        ]);
        $youtubeRetryable = $create([
            'target_provider' => StreamingProvider::YouTube,
            'target_market' => null,
            'status' => ExportReviewStatus::Ready,
            'expires_at' => now()->subSecond(),
            'target_account_id' => 'managed-youtube',
        ]);
        $create([
            'destination_type' => ExportDestinationType::Managed,
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'obsolete-owner',
        ]);

        $response = $this->actingAs($playlist->user)->get(route('bank.index'))->assertOk();
        $renderedPlaylist = $response->original->getData()['playlists']->firstWhere('id', $playlist->id);

        $this->assertEqualsCanonicalizing(
            [$spotifyActive->id, $spotifyRetryable->id, $youtubeActive->id, $youtubeRetryable->id],
            $renderedPlaylist->exportReviews->modelKeys(),
        );
        $this->assertCount(4, $renderedPlaylist->exportReviews);
    }

    private function review(ExportReviewStatus $status = ExportReviewStatus::Ready): ExportReview
    {
        $playlist = $this->playlist();

        return ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => $status,
            'source_fingerprint' => app(FingerprintPlaylist::class)->handle($playlist->load('items')),
            'target_account_id' => 'managed-spotify',
        ]);
    }

    private function playlist(array $attributes = []): Playlist
    {
        $playlist = Playlist::factory()->create($attributes);
        PlaylistItem::factory()->for($playlist)->create();

        return $playlist->load('user');
    }

    private function startPayload(): array
    {
        return ['target_provider' => 'spotify', 'destination_type' => 'managed'];
    }

    private function route(string $name): Route
    {
        $route = app('router')->getRoutes()->getByName($name);
        $this->assertInstanceOf(Route::class, $route);

        return $route;
    }
}
