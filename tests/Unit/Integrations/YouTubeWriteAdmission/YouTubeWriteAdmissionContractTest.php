<?php

namespace Tests\Unit\Integrations\YouTubeWriteAdmission;

use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class YouTubeWriteAdmissionContractTest extends TestCase
{
    public function test_operation_types_are_closed_and_stable(): void
    {
        $this->assertSame(
            ['managed-export', 'linked-export', 'source-sync', 'drift-recovery'],
            array_column(YouTubeWriteOperationType::cases(), 'value'),
        );
        $this->assertSame(255, AdmitYouTubeWrite::MAX_OPERATION_ID_LENGTH);
    }

    public function test_business_statuses_are_closed_and_stable(): void
    {
        $this->assertSame(
            ['admitted-new', 'admitted-existing', 'limit-reached'],
            array_column(YouTubeWriteAdmissionStatus::cases(), 'value'),
        );
    }

    public function test_factories_create_mutually_exclusive_typed_results(): void
    {
        $reset = new DateTimeImmutable('2026-09-15T00:00:00-07:00');
        $new = YouTubeWriteAdmissionResult::admittedNew(10, '2026-09-14', $reset);
        $existing = YouTubeWriteAdmissionResult::admittedExisting(10, '2026-09-14', $reset);
        $limited = YouTubeWriteAdmissionResult::limitReached('2026-09-14', $reset);

        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedNew, $new->status);
        $this->assertSame(YouTubeWriteAdmissionStatus::AdmittedExisting, $existing->status);
        $this->assertSame(YouTubeWriteAdmissionStatus::LimitReached, $limited->status);
        $this->assertSame(10, $new->reservationId);
        $this->assertSame(10, $existing->reservationId);
        $this->assertNull($limited->reservationId);
        $this->assertSame('2026-09-14', $limited->quotaDay);
        $this->assertSame($reset, $limited->resetsAt);
    }

    #[DataProvider('invalidResultProvider')]
    public function test_result_rejects_invalid_state_combinations(callable $makeResult): void
    {
        $this->expectException(InvalidArgumentException::class);

        $makeResult();
    }

    /** @return array<string, array{callable(): YouTubeWriteAdmissionResult}> */
    public static function invalidResultProvider(): array
    {
        $reset = new DateTimeImmutable('2026-09-15T00:00:00-07:00');

        return [
            'limit with reservation' => [fn () => new YouTubeWriteAdmissionResult(
                YouTubeWriteAdmissionStatus::LimitReached,
                10,
                '2026-09-14',
                $reset,
            )],
            'admission without reservation' => [fn () => new YouTubeWriteAdmissionResult(
                YouTubeWriteAdmissionStatus::AdmittedNew,
                null,
                '2026-09-14',
                $reset,
            )],
            'non-persisted reservation' => [fn () => YouTubeWriteAdmissionResult::admittedNew(0, '2026-09-14', $reset)],
            'non-canonical quota day' => [fn () => YouTubeWriteAdmissionResult::limitReached('2026-9-14', $reset)],
            'invalid quota day' => [fn () => YouTubeWriteAdmissionResult::limitReached('2026-02-30', $reset)],
            'reset outside provider midnight' => [fn () => YouTubeWriteAdmissionResult::limitReached(
                '2026-09-14',
                new DateTimeImmutable('2026-09-15T01:00:00-07:00'),
            )],
        ];
    }

    public function test_technical_failure_is_separate_and_safe(): void
    {
        $cause = new RuntimeException('database details');
        $failure = new YouTubeWriteAdmissionUnavailable($cause);

        $this->assertSame('YouTube write admission is temporarily unavailable.', $failure->getMessage());
        $this->assertSame($cause, $failure->getPrevious());
        $this->assertStringNotContainsString('database details', $failure->getMessage());
    }
}
