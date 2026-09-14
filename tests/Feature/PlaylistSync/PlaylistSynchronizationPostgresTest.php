<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\PlaylistSync\DispatchPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\StreamingAccount;
use App\Models\User;
use App\Models\YouTubeWriteQuotaState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PlaylistSynchronizationPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // Forked contenders and admission must own visible committed transactions.
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration proof requires PostgreSQL row locks.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('This integration proof requires pcntl_fork.');
        }

        $this->cleanDatabase();
        DB::table('youtube_write_quota_states')->insert([
            'singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY,
            'quota_day' => null,
            'admitted_count' => 0,
            'daily_limit' => null,
        ]);
        config()->set('services.youtube_write_admission.daily_limit', '5');
        $this->beforeApplicationDestroyed(fn () => $this->cleanDatabase());
    }

    public function test_row_lock_serializes_dispatch_and_admission_retry_is_idempotent(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->youtube()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'streaming_account_id' => $account->id,
        ]);
        PlaylistItem::factory()->for($playlist)->create();
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
        ]);
        $barrier = sys_get_temp_dir().'/music-map-sync-dispatch-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            foreach (range(0, 1) as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork synchronization dispatcher.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        Queue::fake();
                        touch("{$barrier}/ready-{$index}");
                        while (! is_file("{$barrier}/go")) {
                            usleep(1000);
                        }
                        $run = app(DispatchPlaylistSynchronization::class)->handle(
                            (int) $sync->getKey(),
                            PlaylistSyncTrigger::Scheduled,
                        );
                        file_put_contents("{$barrier}/result-{$index}", $run === null ? 'existing' : 'created');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents("{$barrier}/error-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }

            $deadline = microtime(true) + 10;
            while (count(glob("{$barrier}/ready-*")) !== 2) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Dispatch contenders did not reach the barrier.');
                }
                usleep(1000);
            }
            touch("{$barrier}/go");

            $results = [];
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $error = is_file("{$barrier}/error-{$index}") ? file_get_contents("{$barrier}/error-{$index}") : '';
                $this->assertSame(0, pcntl_wexitstatus($status), $error);
                $results[] = file_get_contents("{$barrier}/result-{$index}");
            }

            DB::purge();
            sort($results);
            $this->assertSame(['created', 'existing'], $results);
            $this->assertSame(1, $sync->runs()->whereIn('state', ['pending', 'running'])->count());
            $this->assertSame(1, $sync->runs()->count());

            $admission = new ReserveYouTubeWrite;
            $first = $admission->admit(YouTubeWriteOperationType::SourceSync, 'postgres-source-sync-retry');
            $retry = $admission->admit(YouTubeWriteOperationType::SourceSync, 'postgres-source-sync-retry');

            $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $first->status);
            $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $retry->status);
            $this->assertSame($first->reservationId, $retry->reservationId);
            $this->assertDatabaseCount('youtube_write_admissions', 1);
            $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
        } finally {
            touch("{$barrier}/go");
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            $this->cleanDatabase();
            File::deleteDirectory($barrier);
        }

        $this->assertDatabaseCount('playlist_sync_runs', 0);
        $this->assertDatabaseCount('youtube_write_admissions', 0);
        $this->assertDatabaseCount('users', 0);
    }

    private function cleanDatabase(): void
    {
        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->delete();
        User::query()->delete();
    }
}
