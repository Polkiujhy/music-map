<?php

namespace App\Integrations\YouTubeWriteAdmission\Data;

use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionStatus;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class YouTubeWriteAdmissionResult
{
    public function __construct(
        public YouTubeWriteAdmissionStatus $status,
        public ?int $reservationId,
        public string $quotaDay,
        public DateTimeImmutable $resetsAt,
    ) {
        if (($status === YouTubeWriteAdmissionStatus::LimitReached) !== ($reservationId === null)) {
            throw new InvalidArgumentException('Only admitted results may contain a reservation ID.');
        }

        if ($reservationId !== null && $reservationId < 1) {
            throw new InvalidArgumentException('Reservation ID must identify a persisted reservation.');
        }

        $canonicalQuotaDay = DateTimeImmutable::createFromFormat('!Y-m-d', $quotaDay);

        if ($canonicalQuotaDay === false || $canonicalQuotaDay->format('Y-m-d') !== $quotaDay) {
            throw new InvalidArgumentException('Quota day must be a canonical YYYY-MM-DD date.');
        }

        $expectedReset = new DateTimeImmutable(
            $quotaDay.' 00:00:00',
            new DateTimeZone('America/Los_Angeles'),
        );

        if ($expectedReset->modify('+1 day')->getTimestamp() !== $resetsAt->getTimestamp()) {
            throw new InvalidArgumentException('Reset must be the next midnight in America/Los_Angeles.');
        }
    }

    public static function admittedNew(
        int $reservationId,
        string $quotaDay,
        DateTimeImmutable $resetsAt,
    ): self {
        return new self(YouTubeWriteAdmissionStatus::AdmittedNew, $reservationId, $quotaDay, $resetsAt);
    }

    public static function admittedExisting(
        int $reservationId,
        string $quotaDay,
        DateTimeImmutable $resetsAt,
    ): self {
        return new self(YouTubeWriteAdmissionStatus::AdmittedExisting, $reservationId, $quotaDay, $resetsAt);
    }

    public static function limitReached(string $quotaDay, DateTimeImmutable $resetsAt): self
    {
        return new self(YouTubeWriteAdmissionStatus::LimitReached, null, $quotaDay, $resetsAt);
    }
}
