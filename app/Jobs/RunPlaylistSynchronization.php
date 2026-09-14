<?php

namespace App\Jobs;

use App\Actions\PlaylistSync\FailPlaylistSyncRun;
use App\Actions\PlaylistSync\RunPlaylistSynchronization as RunSynchronization;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Models\PlaylistSyncRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RunPlaylistSynchronization implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->tries = max(1, (int) config('playlist-sync.job_tries', 3));
        $this->timeout = min(450, max(1, (int) config('playlist-sync.job_timeout_seconds', 450)));
        $this->backoff = array_values(array_map('intval', config('playlist-sync.job_backoff', [60, 300])));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->synchronizationId()))
            ->releaseAfter(max(1, (int) config('playlist-sync.overlap_release_seconds', 60)))
            ->expireAfter(max(1, (int) config('queue.connections.database.retry_after', 510)))];
    }

    public function handle(RunSynchronization $run, FailPlaylistSyncRun $fail): void
    {
        $outcome = $run->handle($this->runId);

        if (! in_array($outcome, [SourceSyncFailure::RateLimited, SourceSyncFailure::ProviderUnavailable], true)) {
            $finished = PlaylistSyncRun::query()->whereKey($this->runId)->first();
            if ($finished instanceof PlaylistSyncRun
                && in_array($finished->state, ['completed', 'failed', 'superseded', 'cancelled'], true)) {
                Log::info('playlist_synchronization_finished', $this->logContext(
                    $finished->synchronization?->last_failure_code ?? 'none',
                    $finished->state,
                ));
            }

            return;
        }

        Log::warning('playlist_synchronization_retryable_failure', $this->logContext($outcome));

        if ($this->attempts() < $this->tries) {
            $index = min(max(0, $this->attempts() - 1), max(0, count($this->backoff) - 1));
            $this->release($this->backoff[$index] ?? 60);

            return;
        }

        $fail->handle($this->runId, $outcome);
    }

    public function failed(?Throwable $exception): void
    {
        $run = PlaylistSyncRun::query()->whereKey($this->runId)->first();
        if (! $run instanceof PlaylistSyncRun
            || in_array($run->state, ['completed', 'failed', 'superseded', 'cancelled'], true)) {
            return;
        }

        app(FailPlaylistSyncRun::class)->handle($this->runId, 'job-failed');

        Log::warning('playlist_synchronization_job_failed', $this->logContext('job-failed', 'failed'));
    }

    public function uniqueId(): string
    {
        return (string) $this->synchronizationId();
    }

    private function synchronizationId(): int
    {
        return (int) PlaylistSyncRun::query()
            ->whereKey($this->runId)
            ->value('playlist_synchronization_id');
    }

    /** @return array<string, int|string|null> */
    private function logContext(SourceSyncFailure|string $failure, string $outcome = 'retryable-failure'): array
    {
        $run = PlaylistSyncRun::query()
            ->whereKey($this->runId)
            ->with('synchronization.playlist')
            ->first();

        return [
            'run_id' => $this->runId,
            'synchronization_id' => $run?->playlist_synchronization_id,
            'playlist_id' => $run?->synchronization?->playlist_id,
            'trigger' => $run?->trigger?->value,
            'direction' => $run?->direction?->value,
            'provider' => $run?->synchronization?->playlist?->source_provider?->value,
            'outcome' => $outcome,
            'failure_code' => $failure instanceof SourceSyncFailure ? $failure->value : $failure,
        ];
    }
}
