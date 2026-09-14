<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportOperationRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_have_stable_methods_uris_and_security_middleware(): void
    {
        $expectations = [
            'export-operations.show' => [['GET', 'HEAD'], 'bank/playlists/{playlist}/exports/{exportOperation}', null],
            'export-operations.retry' => [['POST'], 'bank/playlists/{playlist}/exports/{exportOperation}/retry', 'throttle:export-operation-action'],
            'export-operations.abandon-recovery' => [['POST'], 'bank/playlists/{playlist}/exports/{exportOperation}/abandon-recovery', 'throttle:export-operation-action'],
        ];

        foreach ($expectations as $name => [$methods, $uri, $throttle]) {
            $route = $this->namedRoute($name);
            $this->assertSame($methods, $route->methods());
            $this->assertSame($uri, $route->uri());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('verified', $route->gatherMiddleware());
            if ($throttle !== null) {
                $this->assertContains($throttle, $route->gatherMiddleware());
            }
        }
    }

    public function test_result_requires_authentication_and_verified_email(): void
    {
        $operation = $this->operation();

        $this->get($this->url($operation))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->get($this->url($operation))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_owner_can_open_result_but_cross_user_and_wrong_source_are_hidden(): void
    {
        $operation = $this->operation();
        $otherSource = Playlist::factory()->for($operation->user)->create([
            'source_playlist_id' => 'PL-other-source',
        ]);

        $this->actingAs($operation->user)
            ->get($this->url($operation))
            ->assertOk()
            ->assertSee('W trakcie przenoszenia');

        $this->actingAs(User::factory()->create())
            ->get($this->url($operation))
            ->assertNotFound();

        $this->actingAs($operation->user)
            ->get(route('export-operations.show', [$otherSource, $operation->operation_id]))
            ->assertNotFound();
    }

    public function test_retry_reuses_the_operation_id_and_dispatches_the_same_operation(): void
    {
        Queue::fake([ExecuteExportOperation::class]);
        $operation = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::AmbiguousCreate,
            'provider_mutation_started_at' => now()->subMinute(),
            'completed_at' => now(),
            'attempt_count' => 3,
        ]);

        $this->actingAs($operation->user)
            ->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
            ->assertRedirect($this->url($operation));

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Queued, $operation->status);
        $this->assertSame(0, $operation->attempt_count);
        $this->assertDatabaseCount('export_operations', 1);
        Queue::assertPushed(
            ExecuteExportOperation::class,
            fn (ExecuteExportOperation $job): bool => $job->operationId === $operation->operation_id,
        );
    }

    public function test_double_retry_dispatches_the_operation_only_once(): void
    {
        Queue::fake([ExecuteExportOperation::class]);
        $operation = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::TemporaryFailure,
            'provider_mutation_started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $url = route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]);
        $this->actingAs($operation->user);

        $this->post($url)->assertRedirect($this->url($operation));
        $this->post($url)->assertSessionHasErrors('operation');

        $this->assertSame(ExportOperationStatus::Queued, $operation->fresh()->status);
        Queue::assertPushed(ExecuteExportOperation::class, 1);
    }

    public function test_retry_endpoint_is_throttled_and_never_exposes_a_foreign_operation(): void
    {
        Queue::fake();
        $operation = $this->operation([
            'status' => ExportOperationStatus::Failed,
            'failure_code' => ExportOperationFailure::TargetDeleted,
            'active_key' => null,
            'completed_at' => now(),
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->actingAs($operation->user)
                ->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
                ->assertSessionHasErrors('operation');
        }

        $this->actingAs($operation->user)
            ->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
            ->assertTooManyRequests();

        $this->actingAs(User::factory()->create())
            ->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
            ->assertNotFound();
    }

    public function test_unsupported_duplicate_cannot_retry_even_with_a_stale_active_key(): void
    {
        Queue::fake();
        $operation = $this->operation([
            'status' => ExportOperationStatus::Failed,
            'failure_code' => ExportOperationFailure::UnsupportedDuplicate,
            'completed_at' => now(),
        ]);

        $this->actingAs($operation->user)
            ->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
            ->assertSessionHasErrors('operation');

        $this->assertSame(ExportOperationStatus::Failed, $operation->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_abandon_recovery_requires_confirmation_and_releases_the_active_key(): void
    {
        Queue::fake();
        $operation = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::AmbiguousCreate,
            'provider_mutation_started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $url = route('export-operations.abandon-recovery', [
            $operation->source_playlist_id,
            $operation->operation_id,
        ]);

        $this->actingAs($operation->user)
            ->post($url)
            ->assertSessionHasErrors('orphan_copies_checked');
        $this->assertNotNull($operation->fresh()->active_key);

        $this->actingAs($operation->user)
            ->post($url, ['orphan_copies_checked' => '1'])
            ->assertRedirect($this->url($operation));

        $operation->refresh();
        $this->assertSame(ExportOperationStatus::Failed, $operation->status);
        $this->assertSame(ExportOperationFailure::RecoveryAbandoned, $operation->failure_code);
        $this->assertNull($operation->active_key);
        Queue::assertNothingPushed();
    }

    public function test_mutating_routes_reject_requests_without_a_csrf_token(): void
    {
        $operation = $this->operation([
            'status' => ExportOperationStatus::Incomplete,
            'failure_code' => ExportOperationFailure::AmbiguousCreate,
            'completed_at' => now(),
        ]);
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $middleware);
        $this->actingAs($operation->user);

        $this->post(route('export-operations.retry', [$operation->source_playlist_id, $operation->operation_id]))
            ->assertStatus(419);
        $this->post(route('export-operations.abandon-recovery', [$operation->source_playlist_id, $operation->operation_id]), [
            'orphan_copies_checked' => '1',
        ])->assertStatus(419);
    }

    public function test_http_confirmation_redirects_to_the_canonical_operation_result(): void
    {
        Queue::fake();
        $operation = $this->operation();
        $operation->exportReview->update([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($operation->user)
            ->post(route('export-reviews.confirm', [
                $operation->source_playlist_id,
                $operation->export_review_id,
            ]), ['decisions' => []])
            ->assertRedirect($this->url($operation));
    }

    /** @param array<string, mixed> $overrides */
    private function operation(array $overrides = []): ExportOperation
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $review = ExportReview::factory()->for($playlist)->create(['user_id' => $user->id]);

        return ExportOperation::factory()->create(array_merge([
            'export_review_id' => $review->id,
            'user_id' => $user->id,
            'source_playlist_id' => $playlist->id,
        ], $overrides))->load('user');
    }

    private function url(ExportOperation $operation): string
    {
        return route('export-operations.show', [
            $operation->source_playlist_id,
            $operation->operation_id,
        ]);
    }

    private function namedRoute(string $name): Route
    {
        $route = app('router')->getRoutes()->getByName($name);
        $this->assertInstanceOf(Route::class, $route);

        return $route;
    }
}
