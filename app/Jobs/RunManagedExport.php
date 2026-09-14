<?php

namespace App\Jobs;

use App\Actions\ManagedAccountExport\RunManagedExport as RunManagedExportAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunManagedExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 450;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $exportOperationId) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("managed-export:{$this->exportOperationId}"))
                ->expireAfter(510)
                ->dontRelease(),
        ];
    }

    public function handle(RunManagedExportAction $run): void
    {
        $retryAfter = $run->handle($this->exportOperationId);

        if ($retryAfter !== null && $this->attempts() < $this->tries) {
            $this->release($retryAfter);
        }
    }
}
