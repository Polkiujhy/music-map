<?php

namespace App\Jobs;

use App\Actions\Exports\RecoverStaleExportOperation;
use App\Actions\Exports\RunExportOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class ExecuteExportOperation implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    public const TIMEOUT_SECONDS = 450;

    public const OVERLAP_LEASE_SECONDS = 480;

    public const RETRY_AFTER_FLOOR = 510;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = self::MAX_ATTEMPTS;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $operationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-operation:{$this->operationId}"))
                ->expireAfter(self::OVERLAP_LEASE_SECONDS)
                ->dontRelease(),
        ];
    }

    public function handle(RunExportOperation $run): void
    {
        $run->handle($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(RecoverStaleExportOperation::class)->fail(
            $this->operationId,
            $exception ?? new \RuntimeException('The export job failed without an exception.'),
        );
    }
}
