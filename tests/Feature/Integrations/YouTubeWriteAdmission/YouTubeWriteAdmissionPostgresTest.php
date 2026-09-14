<?php

namespace Tests\Feature\Integrations\YouTubeWriteAdmission;

use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class YouTubeWriteAdmissionPostgresTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // Admission owns its transaction; the test harness must not create an ambient one.
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')) {
            $this->markTestSkipped('This admission proof requires PostgreSQL, pcntl, and posix.');
        }

        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null],
        );
        Carbon::setTestNow('2026-09-14 12:00:00 UTC');
        config()->set('services.youtube_write_admission.daily_limit', '5');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_parallel_distinct_keys_never_exceed_the_global_limit(): void
    {
        $outcomes = $this->runConcurrent(array_map(
            static fn (int $index): array => [
                YouTubeWriteOperationType::ManagedExport,
                "parallel-distinct-{$index}",
                '3',
            ],
            range(1, 4),
        ));

        $statuses = array_column($outcomes, 'status');

        $this->assertSame(3, count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === YouTubeWriteAdmissionStatus::AdmittedNew->value,
        )));
        $this->assertSame(1, count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === YouTubeWriteAdmissionStatus::LimitReached->value,
        )));
        $this->assertSame(3, YouTubeWriteAdmission::query()->count());

        $state = YouTubeWriteQuotaState::query()->firstOrFail();
        $this->assertSame(3, $state->admitted_count);
        $this->assertSame(3, $state->daily_limit);
    }

    public function test_parallel_retries_of_one_key_create_one_permanent_reservation(): void
    {
        $outcomes = $this->runConcurrent(array_fill(0, 4, [
            YouTubeWriteOperationType::SourceSync,
            'parallel-shared-key',
            '5',
        ]));
        $statuses = array_column($outcomes, 'status');

        $this->assertSame(1, count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === YouTubeWriteAdmissionStatus::AdmittedNew->value,
        )));
        $this->assertSame(3, count(array_filter(
            $statuses,
            static fn (string $status): bool => $status === YouTubeWriteAdmissionStatus::AdmittedExisting->value,
        )));
        $this->assertCount(1, array_unique(array_column($outcomes, 'reservation_id')));
        $this->assertSame(1, YouTubeWriteAdmission::query()->count());
        $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
    }

    public function test_quota_day_is_read_after_the_global_lock_is_acquired(): void
    {
        $barrier = $this->newBarrier('day-after-lock');
        $clock = "{$barrier}/clock";
        $applicationName = 'youtube-write-day-waiter-'.Str::uuid();
        file_put_contents($clock, '2026-09-14 06:59:59 UTC');
        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork quota-day contender.');
        }

        if ($pid === 0) {
            try {
                DB::purge();
                DB::select("select set_config('application_name', ?, false)", [$applicationName]);
                Carbon::setTestNow(static fn (): CarbonImmutable => new CarbonImmutable(trim(file_get_contents($clock))));
                touch("{$barrier}/ready");
                $this->waitForFile("{$barrier}/go", 'Quota-day barrier timed out.');

                $result = (new ReserveYouTubeWrite)->admit(
                    YouTubeWriteOperationType::DriftRecovery,
                    'day-after-lock',
                );
                file_put_contents("{$barrier}/result", json_encode([
                    'status' => $result->status->value,
                    'quota_day' => $result->quotaDay,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                $this->recordChildError($barrier, 0, $exception);
                exit(1);
            }
        }

        try {
            $this->waitForFile("{$barrier}/ready", 'Quota-day contender did not start.');
            DB::purge();
            DB::beginTransaction();
            YouTubeWriteQuotaState::query()
                ->whereKey(YouTubeWriteQuotaState::GLOBAL_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            touch("{$barrier}/go");

            $this->waitForPostgresLock($applicationName);
            file_put_contents($clock, '2026-09-14 07:00:01 UTC');
            DB::commit();

            $this->assertChildSucceeded($pid, $barrier, 0);
            $result = json_decode(file_get_contents("{$barrier}/result"), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew->value, $result['status']);
            $this->assertSame('2026-09-14', $result['quota_day']);
            $this->assertSame('2026-09-14', YouTubeWriteQuotaState::query()->firstOrFail()->quota_day->format('Y-m-d'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            touch("{$barrier}/go");
            $this->reapChildren([$pid]);
            DB::purge();
            File::deleteDirectory($barrier);
        }
    }

    public function test_process_restart_and_mixed_configuration_keep_the_first_daily_snapshot(): void
    {
        $first = $this->runConcurrent([[
            YouTubeWriteOperationType::ManagedExport,
            'rolling-first',
            '2',
        ]])[0];
        $second = $this->runConcurrent([[
            YouTubeWriteOperationType::LinkedExport,
            'rolling-second',
            '9',
        ]])[0];
        $denied = $this->runConcurrent([[
            YouTubeWriteOperationType::SourceSync,
            'rolling-third',
            '9',
        ]])[0];

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew->value, $first['status']);
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew->value, $second['status']);
        $this->assertSame(YouTubeWriteAdmissionStatus::LimitReached->value, $denied['status']);
        $this->assertSame(2, YouTubeWriteQuotaState::query()->firstOrFail()->daily_limit);

        Carbon::setTestNow('2026-09-15 12:00:00 UTC');
        $nextDay = $this->runConcurrent([[
            YouTubeWriteOperationType::DriftRecovery,
            'rolling-next-day',
            '9',
        ]])[0];

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew->value, $nextDay['status']);
        $this->assertSame(9, YouTubeWriteQuotaState::query()->firstOrFail()->daily_limit);
    }

    public function test_lock_timeout_is_mapped_once_without_returning_an_admission(): void
    {
        $barrier = $this->newBarrier('lock-timeout');
        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork lock holder.');
        }

        if ($pid === 0) {
            try {
                DB::purge();
                DB::beginTransaction();
                YouTubeWriteQuotaState::query()
                    ->whereKey(YouTubeWriteQuotaState::GLOBAL_KEY)
                    ->lockForUpdate()
                    ->firstOrFail();
                touch("{$barrier}/locked");
                $this->waitForFile("{$barrier}/release", 'Lock holder release timed out.');
                DB::rollBack();
                exit(0);
            } catch (Throwable $exception) {
                $this->recordChildError($barrier, 0, $exception);
                exit(1);
            }
        }

        try {
            $this->waitForFile("{$barrier}/locked", 'Lock holder did not acquire the row lock.');
            DB::purge();
            $startedAt = microtime(true);

            try {
                (new ReserveYouTubeWrite)->admit(
                    YouTubeWriteOperationType::ManagedExport,
                    'lock-timeout',
                );
                $this->fail('A lock timeout must not return an admission result.');
            } catch (YouTubeWriteAdmissionUnavailable $exception) {
                $elapsed = microtime(true) - $startedAt;
                $this->assertGreaterThanOrEqual(0.8, $elapsed);
                $this->assertLessThan(2.5, $elapsed, 'SQLSTATE 55P03 must not be retried.');
                $this->assertSame('55P03', $this->sqlState($exception));
                $this->assertDatabaseMissing('youtube_write_admissions', ['operation_id' => 'lock-timeout']);
            }
        } finally {
            touch("{$barrier}/release");
            $this->assertChildSucceeded($pid, $barrier, 0);
            DB::purge();
            File::deleteDirectory($barrier);
        }
    }

    #[DataProvider('retryableSqlStateProvider')]
    public function test_retryable_transaction_failures_stop_after_three_full_attempts(string $sqlState): void
    {
        DB::unprepared('CREATE SEQUENCE youtube_write_retry_attempt_sequence START 1');
        DB::unprepared(<<<SQL
CREATE FUNCTION fail_youtube_write_quota_update()
RETURNS trigger AS \$\$
BEGIN
    PERFORM nextval('youtube_write_retry_attempt_sequence');
    RAISE EXCEPTION 'forced retryable admission failure' USING ERRCODE = '{$sqlState}';
END;
\$\$ LANGUAGE plpgsql
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_youtube_write_quota_update
BEFORE UPDATE ON youtube_write_quota_states
FOR EACH ROW EXECUTE FUNCTION fail_youtube_write_quota_update()
SQL);

        try {
            try {
                (new ReserveYouTubeWrite)->admit(
                    YouTubeWriteOperationType::ManagedExport,
                    "retry-limit-{$sqlState}",
                );
                $this->fail('Exhausted retryable failures must not return an admission result.');
            } catch (YouTubeWriteAdmissionUnavailable $exception) {
                $attempts = (int) DB::scalar('SELECT last_value FROM youtube_write_retry_attempt_sequence');
                $this->assertSame(3, $attempts);
                $this->assertSame($sqlState, $this->sqlState($exception));
                $this->assertDatabaseMissing('youtube_write_admissions', [
                    'operation_id' => "retry-limit-{$sqlState}",
                ]);
                $this->assertSame(0, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_youtube_write_quota_update ON youtube_write_quota_states');
            DB::unprepared('DROP FUNCTION IF EXISTS fail_youtube_write_quota_update()');
            DB::unprepared('DROP SEQUENCE IF EXISTS youtube_write_retry_attempt_sequence');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function retryableSqlStateProvider(): iterable
    {
        yield 'serialization failure' => ['40001'];
        yield 'deadlock detected' => ['40P01'];
    }

    public function test_retry_recovers_after_commit_acknowledgement_is_lost(): void
    {
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();
        $ambiguousDispatcher = new Dispatcher;
        $ambiguousDispatcher->listen(
            TransactionCommitted::class,
            static function (): never {
                throw new RuntimeException('Simulated lost commit acknowledgement.');
            },
        );
        $connection->setEventDispatcher($ambiguousDispatcher);

        try {
            (new ReserveYouTubeWrite)->admit(
                YouTubeWriteOperationType::LinkedExport,
                'ambiguous-commit-key',
            );
            $this->fail('A lost commit acknowledgement must not return an admission result.');
        } catch (YouTubeWriteAdmissionUnavailable $exception) {
            $this->assertSame(
                'Simulated lost commit acknowledgement.',
                $exception->getPrevious()?->getMessage(),
            );
        } finally {
            if ($originalDispatcher === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($originalDispatcher);
            }
        }

        $this->assertDatabaseHas('youtube_write_admissions', [
            'operation_type' => YouTubeWriteOperationType::LinkedExport->value,
            'operation_id' => 'ambiguous-commit-key',
        ]);
        $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);

        DB::purge();

        $retry = $this->runConcurrent([[
            YouTubeWriteOperationType::LinkedExport,
            'ambiguous-commit-key',
            '5',
        ]])[0];

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting->value, $retry['status']);
        $this->assertSame(1, YouTubeWriteAdmission::query()->count());
        $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
    }

    /**
     * @param  list<array{YouTubeWriteOperationType, string, string}>  $contenders
     * @return list<array{status: string, reservation_id: int|null, quota_day: string}>
     */
    private function runConcurrent(array $contenders): array
    {
        $barrier = $this->newBarrier('race');
        DB::disconnect();
        $pids = [];

        try {
            foreach ($contenders as $index => [$operationType, $operationId, $limit]) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork admission contender.');
                }

                if ($pid === 0) {
                    try {
                        DB::purge();
                        config()->set('services.youtube_write_admission.daily_limit', $limit);
                        touch("{$barrier}/ready-{$index}");
                        $this->waitForFile("{$barrier}/go", 'Admission race barrier timed out.');

                        $result = (new ReserveYouTubeWrite)->admit($operationType, $operationId);
                        file_put_contents("{$barrier}/result-{$index}", json_encode([
                            'status' => $result->status->value,
                            'reservation_id' => $result->reservationId,
                            'quota_day' => $result->quotaDay,
                        ], JSON_THROW_ON_ERROR));
                        exit(0);
                    } catch (Throwable $exception) {
                        $this->recordChildError($barrier, $index, $exception);
                        exit(1);
                    }
                }

                $pids[$index] = $pid;
            }

            $deadline = microtime(true) + 10;

            while (count(glob("{$barrier}/ready-*")) !== count($contenders)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Admission contenders did not reach the barrier.');
                }

                usleep(1000);
            }

            touch("{$barrier}/go");

            foreach ($pids as $index => $pid) {
                $this->assertChildSucceeded($pid, $barrier, $index);
            }

            DB::purge();

            return array_map(
                static fn (int $index): array => json_decode(
                    file_get_contents("{$barrier}/result-{$index}"),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                array_keys($contenders),
            );
        } finally {
            touch("{$barrier}/go");

            $this->reapChildren($pids);

            DB::purge();
            File::deleteDirectory($barrier);
        }
    }

    private function newBarrier(string $purpose): string
    {
        $path = sys_get_temp_dir()."/music-map-youtube-write-{$purpose}-".Str::uuid();
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    private function waitForFile(string $path, string $message): void
    {
        $deadline = microtime(true) + 10;

        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException($message);
            }

            usleep(1000);
        }
    }

    private function waitForPostgresLock(string $applicationName): void
    {
        $deadline = microtime(true) + 5;

        do {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'",
                [$applicationName],
            );

            if ($waiting === 1) {
                return;
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Admission contender did not wait on the global lock.');
    }

    private function assertChildSucceeded(int $pid, string $barrier, int $index): void
    {
        $deadline = microtime(true) + 10;

        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);

            if ($waited === $pid) {
                break;
            }

            if ($waited === -1) {
                $this->fail("Admission child {$pid} could not be reaped.");
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        if ($waited !== $pid) {
            $this->reapChildren([$pid]);
            $this->fail("Admission child {$pid} did not exit within 10 seconds.");
        }

        $error = is_file("{$barrier}/error-{$index}")
            ? file_get_contents("{$barrier}/error-{$index}")
            : 'No child error was recorded.';

        $this->assertTrue(pcntl_wifexited($status), $error);
        $this->assertSame(0, pcntl_wexitstatus($status), $error);
    }

    /** @param  list<int>  $pids */
    private function reapChildren(array $pids): void
    {
        $remaining = array_values(array_unique($pids));
        $deadline = microtime(true) + 1;

        do {
            foreach ($remaining as $index => $pid) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);

                if ($waited === $pid || $waited === -1) {
                    unset($remaining[$index]);
                }
            }

            if ($remaining === []) {
                return;
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        foreach ($remaining as $pid) {
            posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + 0.25;

        do {
            foreach ($remaining as $index => $pid) {
                $waited = pcntl_waitpid($pid, $status, WNOHANG);

                if ($waited === $pid || $waited === -1) {
                    unset($remaining[$index]);
                }
            }

            if ($remaining === []) {
                return;
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        foreach ($remaining as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    private function recordChildError(string $barrier, int $index, Throwable $exception): void
    {
        file_put_contents(
            "{$barrier}/error-{$index}",
            $exception::class.': '.$exception->getMessage(),
        );
    }

    private function sqlState(Throwable $exception): ?string
    {
        do {
            if (is_string($exception->getCode()) && preg_match('/\A[0-9A-Z]{5}\z/', $exception->getCode()) === 1) {
                return $exception->getCode();
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }
}
