<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ExecuteExportOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 450;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $operationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-operation:{$this->operationId}"))
                ->expireAfter(480)
                ->dontRelease(),
        ];
    }

    /**
     * Phase 3 supplies the provider orchestration. Keeping this handler a no-op
     * preserves the durable queued intent introduced in Phase 1.
     */
    public function handle(): void {}
}
