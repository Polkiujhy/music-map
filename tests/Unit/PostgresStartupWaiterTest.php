<?php

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../docker/entrypoints/wait-for-postgres.php';

class PostgresStartupWaiterTest extends TestCase
{
    public function test_transient_failures_back_off_exponentially_then_succeed(): void
    {
        $attempts = 0;
        $sleeps = [];
        $waiter = new \PostgresStartupWaiter(
            $this->environment(),
            function () use (&$attempts): void {
                $attempts++;
                if ($attempts < 3) {
                    throw $this->pdoException('08006');
                }
            },
            function (int $milliseconds) use (&$sleeps): void {
                $sleeps[] = $milliseconds;
            },
            static fn (): float => 0.0,
            static fn (): int => 0,
            static fn (): null => null,
        );

        self::assertSame(0, $waiter->run());
        self::assertSame(3, $attempts);
        self::assertSame([500, 1000], $sleeps);
    }

    public function test_total_timeout_stops_the_loop_with_temporary_exit(): void
    {
        $now = 0.0;
        $attempts = 0;
        $sleeps = [];
        $waiter = new \PostgresStartupWaiter(
            array_replace($this->environment(), [
                'DB_STARTUP_INITIAL_BACKOFF_MS' => '2000',
                'DB_STARTUP_TOTAL_TIMEOUT_SECONDS' => '5',
            ]),
            function () use (&$attempts, &$now): void {
                $attempts++;
                $now += 4.0;
                throw $this->pdoException('08006');
            },
            function (int $milliseconds) use (&$sleeps, &$now): void {
                $sleeps[] = $milliseconds;
                $now += $milliseconds / 1000;
            },
            static function () use (&$now): float {
                return $now;
            },
            static fn (): int => 0,
            static fn (): null => null,
        );

        self::assertSame(\PostgresStartupWaiter::EXIT_TEMPORARY, $waiter->run());
        self::assertSame(1, $attempts);
        self::assertSame([1000], $sleeps);
        self::assertSame(5.0, $now);
    }

    public function test_authentication_error_is_immediate_and_secret_free(): void
    {
        $attempts = 0;
        $output = [];
        $waiter = new \PostgresStartupWaiter(
            $this->environment(),
            function () use (&$attempts): void {
                $attempts++;
                throw $this->pdoException('08006', 'password authentication failed for not-for-logs');
            },
            static fn (): null => null,
            static fn (): float => 0.0,
            static fn (): int => 0,
            function (string $message) use (&$output): void {
                $output[] = $message;
            },
        );

        self::assertSame(\PostgresStartupWaiter::EXIT_UNAVAILABLE, $waiter->run());
        self::assertSame(1, $attempts);
        self::assertStringNotContainsString('not-for-logs', implode("\n", $output));
    }

    public function test_wrong_driver_and_invalid_tuning_fail_before_connecting(): void
    {
        foreach ([
            ['DB_CONNECTION' => 'sqlite'],
            ['DB_STARTUP_MAX_ATTEMPTS' => 'unbounded'],
        ] as $override) {
            $attempts = 0;
            $waiter = new \PostgresStartupWaiter(
                array_replace($this->environment(), $override),
                static function () use (&$attempts): void {
                    $attempts++;
                },
                static fn (): null => null,
                static fn (): float => 0.0,
                static fn (): int => 0,
                static fn (): null => null,
            );

            self::assertSame(\PostgresStartupWaiter::EXIT_CONFIGURATION, $waiter->run());
            self::assertSame(0, $attempts);
        }
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'music-map-postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'music_map',
            'DB_USERNAME' => 'music_map',
            'DB_PASSWORD' => 'not-for-logs',
            'DB_SSLMODE' => 'require',
        ];
    }

    private function pdoException(string $sqlState, string $message = 'connection failed'): PDOException
    {
        $exception = new PDOException($message);
        $exception->errorInfo = [$sqlState];

        return $exception;
    }
}
