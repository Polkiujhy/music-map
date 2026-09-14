<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\PlaylistSync\RunPlaylistSynchronization as RunSynchronization;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Jobs\RunPlaylistSynchronization;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Tests\TestCase;

class PlaylistSynchronizationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_serializes_only_safe_run_identity_and_locks_per_synchronization(): void
    {
        $sync = PlaylistSynchronization::factory()->create(['status' => PlaylistSyncStatus::Enabled]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'direction' => PlaylistSyncDirection::NoOp,
        ]);
        $job = new RunPlaylistSynchronization($run->id);

        $serialized = serialize($job);

        $this->assertSame((string) $sync->id, $job->uniqueId());
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertSame(450, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertStringContainsString('runId', $serialized);
        $this->assertStringNotContainsString('refresh_token', $serialized);
        $this->assertStringNotContainsString('access_token', $serialized);
        $this->assertStringNotContainsString('checkpoint', $serialized);
    }

    public function test_terminal_queue_failure_records_attention_without_advancing_the_baseline(): void
    {
        $sync = PlaylistSynchronization::factory()->create([
            'status' => PlaylistSyncStatus::Enabled,
            'automatic_enabled' => true,
            'baseline_bank_fingerprint' => hash('sha256', 'bank-before'),
            'baseline_source_fingerprint' => hash('sha256', 'source-before'),
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create(['state' => 'running']);

        (new RunPlaylistSynchronization($run->id))->failed(new RuntimeException('canary secret must not be logged'));

        $this->assertSame('failed', $run->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Attention, $sync->refresh()->status);
        $this->assertFalse($sync->automatic_enabled);
        $this->assertSame('job-failed', $sync->last_failure_code);
        $this->assertSame(hash('sha256', 'bank-before'), $sync->baseline_bank_fingerprint);
        $this->assertSame(hash('sha256', 'source-before'), $sync->baseline_source_fingerprint);
    }

    public function test_coordinator_leaves_transient_failures_retryable_but_terminal_auth_failure_needs_attention(): void
    {
        [$retrySync, $retryRun] = $this->runnableSynchronization();
        $this->app->instance(WithStreamingAccess::class, new FailingSyncAccess(StreamingAccessFailure::RateLimited));

        $outcome = $this->app->make(RunSynchronization::class)->handle($retryRun->id);

        $this->assertSame(SourceSyncFailure::RateLimited, $outcome);
        $this->assertSame('pending', $retryRun->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Enabled, $retrySync->refresh()->status);

        [$terminalSync, $terminalRun] = $this->runnableSynchronization();
        $this->app->instance(WithStreamingAccess::class, new FailingSyncAccess(StreamingAccessFailure::ReconnectRequired));

        $terminalOutcome = $this->app->make(RunSynchronization::class)->handle($terminalRun->id);

        $this->assertSame(SourceSyncFailure::ReconnectRequired, $terminalOutcome);
        $this->assertSame('failed', $terminalRun->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Attention, $terminalSync->refresh()->status);
        $this->assertSame('reconnect-required', $terminalSync->last_failure_code);
    }

    /** @return array{PlaylistSynchronization, PlaylistSyncRun} */
    private function runnableSynchronization(): array
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::Spotify,
            'streaming_account_id' => $account->id,
        ]);
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => hash('sha256', 'bank-'.$playlist->id),
            'baseline_source_fingerprint' => hash('sha256', 'source-'.$playlist->id),
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create(['state' => 'pending']);

        return [$sync, $run];
    }
}

final readonly class FailingSyncAccess implements WithStreamingAccess
{
    public function __construct(private StreamingAccessFailure $failure) {}

    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        return StreamingAccessResult::failure($this->failure);
    }
}
