<?php

namespace Tests\Feature\PlaylistSync;

use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class YouTubeSourceSyncAdmissionPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // Admission must own its transaction.
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This source-sync admission proof requires PostgreSQL.');
        }

        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null],
        );
        config()->set('services.youtube_write_admission.daily_limit', '5');
    }

    public function test_source_sync_retry_uses_one_permanent_admission_on_postgresql(): void
    {
        $admission = new ReserveYouTubeWrite;

        $first = $admission->admit(YouTubeWriteOperationType::SourceSync, 'source-sync-operation-canary');
        $retry = $admission->admit(YouTubeWriteOperationType::SourceSync, 'source-sync-operation-canary');

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $first->status);
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $retry->status);
        $this->assertSame($first->reservationId, $retry->reservationId);
        $this->assertSame(1, YouTubeWriteAdmission::query()->count());
        $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
    }
}
