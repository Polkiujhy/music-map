<?php

namespace App\Integrations\YouTubeWriteAdmission\Actions;

use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Integrations\YouTubeWriteAdmission\Data\YouTubeWriteAdmissionResult;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteAdmissionUnavailable;
use App\Integrations\YouTubeWriteAdmission\YouTubeWriteOperationType;
use App\Models\YouTubeWriteAdmission;
use App\Models\YouTubeWriteQuotaState;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use Throwable;

final class ReserveYouTubeWrite implements AdmitYouTubeWrite
{
    private const LOCK_TIMEOUT_MS = 1000;

    private const MAX_TRANSACTION_ATTEMPTS = 3;

    private const MAX_DAILY_LIMIT = 2147483647;

    private const RETRYABLE_SQLSTATES = ['40001', '40P01'];

    public function admit(
        YouTubeWriteOperationType $operationType,
        string $operationId,
    ): YouTubeWriteAdmissionResult {
        $this->validateOperationId($operationId);

        $connection = DB::connection();

        if ($connection->transactionLevel() !== 0) {
            throw new YouTubeWriteAdmissionUnavailable;
        }

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                return $connection->transaction(
                    fn (): YouTubeWriteAdmissionResult => $this->reserve(
                        $connection,
                        $operationType,
                        $operationId,
                    ),
                    1,
                );
            } catch (Throwable $exception) {
                $sqlState = $this->sqlState($exception);

                if ($sqlState !== '55P03'
                    && in_array($sqlState, self::RETRYABLE_SQLSTATES, true)
                    && $attempt < self::MAX_TRANSACTION_ATTEMPTS) {
                    continue;
                }

                throw new YouTubeWriteAdmissionUnavailable($exception);
            }
        }

        throw new YouTubeWriteAdmissionUnavailable;
    }

    private function reserve(
        ConnectionInterface $connection,
        YouTubeWriteOperationType $operationType,
        string $operationId,
    ): YouTubeWriteAdmissionResult {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT_MS."ms'");
        }

        $state = YouTubeWriteQuotaState::query()
            ->whereKey(YouTubeWriteQuotaState::GLOBAL_KEY)
            ->lockForUpdate()
            ->first();

        if ($state === null) {
            throw new InvalidArgumentException('The global YouTube write quota state is missing.');
        }

        $now = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'));
        $currentQuotaDay = $now->format('Y-m-d');
        $configuredLimit = $this->configuredLimit();

        $existing = YouTubeWriteAdmission::query()
            ->where('operation_type', $operationType->value)
            ->where('operation_id', $operationId)
            ->first();

        if ($existing !== null) {
            $quotaDay = $existing->quota_day->format('Y-m-d');

            return YouTubeWriteAdmissionResult::admittedExisting(
                $existing->getKey(),
                $quotaDay,
                $this->resetAfter($quotaDay),
            );
        }

        $stateQuotaDay = $state->quota_day?->format('Y-m-d');

        if ($stateQuotaDay === null || $stateQuotaDay < $currentQuotaDay) {
            $state->forceFill([
                'quota_day' => $currentQuotaDay,
                'admitted_count' => 0,
                'daily_limit' => $configuredLimit,
            ]);
        } elseif ($stateQuotaDay > $currentQuotaDay) {
            throw new InvalidArgumentException('The quota clock moved behind the persisted state.');
        }

        if ($state->daily_limit === null
            || $state->daily_limit < 1
            || $state->admitted_count < 0
            || $state->admitted_count > $state->daily_limit) {
            throw new InvalidArgumentException('The global YouTube write quota state is invalid.');
        }

        $resetsAt = $this->resetAfter($currentQuotaDay);

        if ($state->admitted_count >= $state->daily_limit) {
            if ($state->isDirty()) {
                $state->save();
            }

            return YouTubeWriteAdmissionResult::limitReached($currentQuotaDay, $resetsAt);
        }

        $admission = YouTubeWriteAdmission::query()->create([
            'operation_type' => $operationType,
            'operation_id' => $operationId,
            'quota_day' => $currentQuotaDay,
            'admitted_at' => $now,
        ]);

        $state->admitted_count++;
        $state->save();

        return YouTubeWriteAdmissionResult::admittedNew(
            $admission->getKey(),
            $currentQuotaDay,
            $resetsAt,
        );
    }

    private function validateOperationId(string $operationId): void
    {
        if (trim($operationId) === '' || mb_strlen($operationId) > self::MAX_OPERATION_ID_LENGTH) {
            throw new InvalidArgumentException('Operation ID must be non-empty and at most 255 characters.');
        }
    }

    private function configuredLimit(): int
    {
        $value = config('services.youtube_write_admission.daily_limit');

        if (is_int($value)) {
            $canonical = (string) $value;
        } elseif (is_string($value)) {
            $canonical = $value;
        } else {
            throw new InvalidArgumentException('YouTube write daily limit must be a canonical integer.');
        }

        if (preg_match('/\A[1-9][0-9]*\z/', $canonical) !== 1
            || strlen($canonical) > strlen((string) self::MAX_DAILY_LIMIT)
            || (strlen($canonical) === strlen((string) self::MAX_DAILY_LIMIT)
                && strcmp($canonical, (string) self::MAX_DAILY_LIMIT) > 0)) {
            throw new InvalidArgumentException('YouTube write daily limit is outside the supported range.');
        }

        return (int) $canonical;
    }

    private function resetAfter(string $quotaDay): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $quotaDay,
            new DateTimeZone('America/Los_Angeles'),
        )->addDay();
    }

    private function sqlState(Throwable $exception): ?string
    {
        do {
            if (is_string($exception->getCode()) && preg_match('/\A[0-9A-Z]{5}\z/', $exception->getCode()) === 1) {
                return $exception->getCode();
            }

            if ($exception instanceof PDOException
                && is_array($exception->errorInfo)
                && isset($exception->errorInfo[0])
                && is_string($exception->errorInfo[0])) {
                return $exception->errorInfo[0];
            }

            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return null;
    }
}
