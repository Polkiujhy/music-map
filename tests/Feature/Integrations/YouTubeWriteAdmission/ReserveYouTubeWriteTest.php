<?php

namespace Tests\Feature\Integrations\YouTubeWriteAdmission;

use App\Integrations\YouTubeWriteAdmission\Actions\ReserveYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReserveYouTubeWriteTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // Admission owns its transaction; the test harness must not create an ambient one.
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null],
        );
        Carbon::setTestNow('2026-09-14 12:00:00 UTC');
        config()->set('services.youtube_write_admission.daily_limit', '2');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_container_resolves_the_public_port_to_the_transactional_action(): void
    {
        $this->assertInstanceOf(ReserveYouTubeWrite::class, app(AdmitYouTubeWrite::class));
    }

    public function test_first_n_operations_are_admitted_and_n_plus_one_has_no_side_effect(): void
    {
        $action = app(AdmitYouTubeWrite::class);

        $first = $action->admit(YouTubeWriteOperationType::ManagedExport, 'one');
        $second = $action->admit(YouTubeWriteOperationType::LinkedExport, 'two');
        $denied = $action->admit(YouTubeWriteOperationType::SourceSync, 'three');

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $first->status);
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $second->status);
        $this->assertSame(YouTubeWriteAdmissionStatus::LimitReached, $denied->status);
        $this->assertNull($denied->reservationId);
        $this->assertSame('2026-09-14', $denied->quotaDay);
        $this->assertSame(2, YouTubeWriteAdmission::query()->count());
        $this->assertSame(2, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
    }

    public function test_retry_is_permanent_across_days_instances_and_a_later_consumer_failure(): void
    {
        $first = app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'stable');
        $sameDayRetry = app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'stable');

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $sameDayRetry->status);
        $this->assertSame($first->reservationId, $sameDayRetry->reservationId);

        try {
            throw new \RuntimeException('A future provider request failed after admission committed.');
        } catch (\RuntimeException) {
            // The committed reservation is intentionally not refunded.
        }

        Carbon::setTestNow('2026-09-15 12:00:00 UTC');
        $retry = (new ReserveYouTubeWrite)->admit(YouTubeWriteOperationType::ManagedExport, 'stable');

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $retry->status);
        $this->assertSame($first->reservationId, $retry->reservationId);
        $this->assertSame('2026-09-14', $retry->quotaDay);
        $this->assertSame(1, YouTubeWriteAdmission::query()->count());
        $this->assertSame(1, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
    }

    public function test_same_id_for_different_operation_types_consumes_distinct_slots(): void
    {
        $action = app(AdmitYouTubeWrite::class);

        $managed = $action->admit(YouTubeWriteOperationType::ManagedExport, 'same');
        $linked = $action->admit(YouTubeWriteOperationType::LinkedExport, 'same');

        $this->assertNotSame($managed->reservationId, $linked->reservationId);
        $this->assertSame(2, YouTubeWriteAdmission::query()->count());
    }

    public function test_limit_is_snapshotted_until_the_next_quota_day(): void
    {
        $action = app(AdmitYouTubeWrite::class);
        $action->admit(YouTubeWriteOperationType::ManagedExport, 'day-one-a');
        config()->set('services.youtube_write_admission.daily_limit', '1');

        $this->assertSame(
            YouTubeWriteAdmissionStatus::AdmittedNew,
            $action->admit(YouTubeWriteOperationType::ManagedExport, 'day-one-b')->status,
        );

        Carbon::setTestNow('2026-09-15 12:00:00 UTC');
        $this->assertSame(
            YouTubeWriteAdmissionStatus::AdmittedNew,
            $action->admit(YouTubeWriteOperationType::ManagedExport, 'day-two-a')->status,
        );
        $this->assertSame(
            YouTubeWriteAdmissionStatus::LimitReached,
            $action->admit(YouTubeWriteOperationType::ManagedExport, 'day-two-b')->status,
        );

        $state = YouTubeWriteQuotaState::query()->firstOrFail();
        $this->assertSame('2026-09-15', $state->quota_day->format('Y-m-d'));
        $this->assertSame(1, $state->daily_limit);
        $this->assertSame(1, $state->admitted_count);
    }

    #[DataProvider('invalidLimitProvider')]
    public function test_invalid_configuration_fails_closed_without_writes(mixed $limit): void
    {
        config()->set('services.youtube_write_admission.daily_limit', $limit);

        try {
            app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'invalid-limit');
            $this->fail('Invalid configuration must fail closed.');
        } catch (YouTubeWriteAdmissionUnavailable) {
            $this->assertSame(0, YouTubeWriteAdmission::query()->count());
            $this->assertNull(YouTubeWriteQuotaState::query()->firstOrFail()->quota_day);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLimitProvider(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'partially numeric' => ['5x'];
        yield 'floating point' => ['5.0'];
        yield 'overflow' => ['2147483648'];
    }

    #[DataProvider('invalidOperationIdProvider')]
    public function test_operation_id_is_validated_before_database_access(string $operationId): void
    {
        DB::table('youtube_write_quota_states')->delete();

        $this->expectException(InvalidArgumentException::class);
        app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, $operationId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperationIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('a', AdmitYouTubeWrite::MAX_OPERATION_ID_LENGTH + 1)];
    }

    public function test_missing_global_state_and_nested_transaction_fail_closed(): void
    {
        DB::table('youtube_write_quota_states')->delete();

        try {
            app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'missing-state');
            $this->fail('Missing global state must fail closed.');
        } catch (YouTubeWriteAdmissionUnavailable) {
            $this->assertSame(0, YouTubeWriteAdmission::query()->count());
        }

        DB::table('youtube_write_quota_states')->insert([
            'singleton_key' => 'global', 'quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null,
        ]);

        try {
            DB::transaction(function (): void {
                app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'nested');
            });
            $this->fail('Nested transactions must be rejected.');
        } catch (YouTubeWriteAdmissionUnavailable) {
            $this->assertSame(0, YouTubeWriteAdmission::query()->count());
        }
    }

    public function test_database_error_rolls_back_the_admission_and_state_increment(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER reject_quota_state_update
BEFORE UPDATE ON youtube_write_quota_states
BEGIN
    SELECT RAISE(ABORT, 'forced state failure');
END
SQL);

        try {
            app(AdmitYouTubeWrite::class)->admit(YouTubeWriteOperationType::ManagedExport, 'rollback');
            $this->fail('A database failure must be mapped to unavailability.');
        } catch (YouTubeWriteAdmissionUnavailable) {
            $this->assertSame(0, YouTubeWriteAdmission::query()->count());
            $this->assertSame(0, YouTubeWriteQuotaState::query()->firstOrFail()->admitted_count);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_quota_state_update');
        }
    }

    public function test_clock_rollback_allows_known_key_but_rejects_new_key(): void
    {
        $action = app(AdmitYouTubeWrite::class);
        $known = $action->admit(YouTubeWriteOperationType::ManagedExport, 'known');
        Carbon::setTestNow('2026-09-15 12:00:00 UTC');
        $action->admit(YouTubeWriteOperationType::ManagedExport, 'tomorrow');
        Carbon::setTestNow('2026-09-14 12:00:00 UTC');

        $existing = $action->admit(YouTubeWriteOperationType::ManagedExport, 'known');
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $existing->status);
        $this->assertSame($known->reservationId, $existing->reservationId);

        try {
            $action->admit(YouTubeWriteOperationType::ManagedExport, 'unknown');
            $this->fail('A new key must fail closed when the clock moves backwards.');
        } catch (YouTubeWriteAdmissionUnavailable) {
            $this->assertDatabaseMissing('youtube_write_admissions', ['operation_id' => 'unknown']);
        }
    }

    #[DataProvider('pacificBoundaryProvider')]
    public function test_quota_day_and_reset_follow_pacific_dst_boundaries(
        string $now,
        string $expectedDay,
        string $expectedReset,
    ): void {
        Carbon::setTestNow($now);

        $result = app(AdmitYouTubeWrite::class)->admit(
            YouTubeWriteOperationType::DriftRecovery,
            'boundary-'.$expectedDay,
        );

        $this->assertSame($expectedDay, $result->quotaDay);
        $this->assertSame($expectedReset, $result->resetsAt->format('c'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function pacificBoundaryProvider(): iterable
    {
        yield 'PST before midnight' => [
            '2026-01-15 07:59:59 UTC', '2026-01-14', '2026-01-15T00:00:00-08:00',
        ];
        yield 'PDT after midnight' => [
            '2026-07-15 07:00:00 UTC', '2026-07-15', '2026-07-16T00:00:00-07:00',
        ];
    }
}
