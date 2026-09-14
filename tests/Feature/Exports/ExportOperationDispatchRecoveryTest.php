<?php

namespace Tests\Feature\Exports;

use App\Enums\ExportOperationStatus;
use App\Jobs\ExecuteExportOperation;
use App\Models\ExportOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportOperationDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciler_dispatches_only_queued_operations_at_least_two_minutes_old(): void
    {
        Queue::fake();
        $orphan = ExportOperation::factory()->create(['created_at' => now()->subMinutes(3)]);
        ExportOperation::factory()->create([
            'active_key' => hash('sha256', 'fresh'),
            'created_at' => now()->subMinute(),
        ]);
        ExportOperation::factory()->create([
            'active_key' => null,
            'status' => ExportOperationStatus::Transferred,
            'created_at' => now()->subMinutes(3),
        ]);

        Artisan::call('exports:reconcile-queued');

        Queue::assertPushed(ExecuteExportOperation::class, 1);
        Queue::assertPushed(ExecuteExportOperation::class, fn ($job): bool => $job->operationId === $orphan->operation_id);
        $this->assertDatabaseCount('export_operations', 3);
    }
}
