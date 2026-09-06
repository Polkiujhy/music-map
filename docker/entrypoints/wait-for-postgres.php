<?php

declare(strict_types=1);

final class PostgresStartupWaiter
{
    public const EXIT_CONFIGURATION = 64;

    public const EXIT_UNAVAILABLE = 69;

    public const EXIT_TEMPORARY = 75;

    private Closure $connector;

    private Closure $sleep;

    private Closure $clock;

    private Closure $jitter;

    private Closure $writer;

    /** @param array<string, mixed> $environment */
    public function __construct(
        private readonly array $environment,
        ?Closure $connector = null,
        ?Closure $sleep = null,
        ?Closure $clock = null,
        ?Closure $jitter = null,
        ?Closure $writer = null,
    ) {
        $this->connector = $connector ?? static function (array $connection, int $timeout): void {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d',
                $connection['host'],
                $connection['port'],
                $connection['database'],
                $connection['sslmode'],
                $timeout,
            );
            $pdo = new PDO($dsn, $connection['username'], $connection['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');
        };
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->jitter = $jitter ?? static fn (int $maximum): int => random_int(0, $maximum);
        $this->writer = $writer ?? static function (string $message): void {
            fwrite(STDERR, $message."\n");
        };
    }

    public function run(): int
    {
        try {
            $connection = $this->connection();
            $settings = $this->settings();
        } catch (InvalidArgumentException $exception) {
            ($this->writer)('database startup configuration invalid: '.$exception->getMessage());

            return self::EXIT_CONFIGURATION;
        }

        $deadline = ($this->clock)() + $settings['total_timeout_seconds'];
        $attemptsMade = 0;

        for ($attempt = 1; $attempt <= $settings['max_attempts']; $attempt++) {
            $remaining = $deadline - ($this->clock)();
            if ($remaining < 1.0) {
                break;
            }

            $attemptsMade = $attempt;
            $connectTimeout = max(1, min($settings['connect_timeout_seconds'], (int) floor($remaining)));

            try {
                ($this->connector)($connection, $connectTimeout);
                ($this->writer)(sprintf('database startup probe passed on attempt %d', $attempt));

                return 0;
            } catch (PDOException $exception) {
                if (! self::isTransient($exception)) {
                    ($this->writer)('database startup probe failed with a non-retryable database or authentication error');

                    return self::EXIT_UNAVAILABLE;
                }
            } catch (Throwable) {
                ($this->writer)('database startup probe failed with a non-retryable internal error');

                return self::EXIT_UNAVAILABLE;
            }

            if ($attempt === $settings['max_attempts']) {
                break;
            }

            $remainingMilliseconds = (int) floor(($deadline - ($this->clock)()) * 1000);
            if ($remainingMilliseconds <= 0) {
                break;
            }

            $baseDelay = min(
                $settings['max_backoff_ms'],
                $settings['initial_backoff_ms'] * (2 ** ($attempt - 1)),
            );
            $jitterLimit = max(1, intdiv($baseDelay, 4));
            $jitter = ($this->jitter)($jitterLimit);
            if (! is_int($jitter) || $jitter < 0 || $jitter > $jitterLimit) {
                ($this->writer)('database startup probe failed with an invalid jitter result');

                return self::EXIT_UNAVAILABLE;
            }

            $delay = min($baseDelay + $jitter, $remainingMilliseconds);
            ($this->writer)(sprintf(
                'database startup probe attempt %d was transient; retrying in %dms',
                $attempt,
                $delay,
            ));
            ($this->sleep)($delay);
        }

        ($this->writer)(sprintf(
            'database startup probe exhausted its bounded budget after %d attempt(s)',
            $attemptsMade,
        ));

        return self::EXIT_TEMPORARY;
    }

    public static function isTransient(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        foreach ([
            'authentication failed',
            'certificate verify failed',
            'database "',
            'invalid connection option',
            'no pg_hba.conf entry',
            'role "',
            'server does not support ssl',
            'sslmode value',
        ] as $nonRetryableFragment) {
            if (str_contains($message, $nonRetryableFragment)) {
                return false;
            }
        }

        $sqlState = null;
        if (is_array($exception->errorInfo ?? null) && isset($exception->errorInfo[0])) {
            $sqlState = strtoupper((string) $exception->errorInfo[0]);
        } elseif (is_string($exception->getCode()) && preg_match('/^[A-Z0-9]{5}$/', $exception->getCode()) === 1) {
            $sqlState = strtoupper($exception->getCode());
        }

        if ($sqlState !== null) {
            if (str_starts_with($sqlState, '08') || str_starts_with($sqlState, '53')) {
                return true;
            }

            return in_array($sqlState, ['57P03', 'HYT00', 'HYT01'], true);
        }

        foreach ([
            'connection refused',
            'connection timed out',
            'could not connect to server',
            'could not translate host name',
            'name or service not known',
            'network is unreachable',
            'no route to host',
            'server closed the connection',
            'temporary failure',
            'timeout expired',
        ] as $transientFragment) {
            if (str_contains($message, $transientFragment)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string, sslmode: string} */
    private function connection(): array
    {
        if ($this->required('DB_CONNECTION') !== 'pgsql') {
            throw new InvalidArgumentException('DB_CONNECTION must be pgsql');
        }

        $host = $this->required('DB_HOST');
        $database = $this->required('DB_DATABASE');
        $username = $this->required('DB_USERNAME');
        $password = $this->required('DB_PASSWORD');
        $sslmode = $this->optional('DB_SSLMODE', 'require');
        $port = $this->integer('DB_PORT', 5432, 1, 65535);

        if (preg_match('/^[A-Za-z0-9_.:\[\]-]+$/', $host) !== 1) {
            throw new InvalidArgumentException('DB_HOST has an unsupported format');
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $database) !== 1) {
            throw new InvalidArgumentException('DB_DATABASE has an unsupported format');
        }
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $username) !== 1) {
            throw new InvalidArgumentException('DB_USERNAME has an unsupported format');
        }
        if (! in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new InvalidArgumentException('DB_SSLMODE is not supported');
        }

        return compact('host', 'port', 'database', 'username', 'password', 'sslmode');
    }

    /** @return array{max_attempts: int, initial_backoff_ms: int, max_backoff_ms: int, connect_timeout_seconds: int, total_timeout_seconds: int} */
    private function settings(): array
    {
        $initialBackoff = $this->integer('DB_STARTUP_INITIAL_BACKOFF_MS', 500, 100, 10_000);

        return [
            'max_attempts' => $this->integer('DB_STARTUP_MAX_ATTEMPTS', 7, 1, 12),
            'initial_backoff_ms' => $initialBackoff,
            'max_backoff_ms' => $this->integer('DB_STARTUP_MAX_BACKOFF_MS', 8_000, $initialBackoff, 30_000),
            'connect_timeout_seconds' => $this->integer('DB_STARTUP_CONNECT_TIMEOUT_SECONDS', 3, 1, 10),
            'total_timeout_seconds' => $this->integer('DB_STARTUP_TOTAL_TIMEOUT_SECONDS', 45, 5, 120),
        ];
    }

    private function required(string $key): string
    {
        $value = $this->optional($key, '');
        if ($value === '') {
            throw new InvalidArgumentException($key.' is required');
        }

        return $value;
    }

    private function optional(string $key, string $default): string
    {
        $value = $this->environment[$key] ?? $default;
        if (! is_string($value)) {
            throw new InvalidArgumentException($key.' must be a string');
        }

        return $value;
    }

    private function integer(string $key, int $default, int $minimum, int $maximum): int
    {
        $raw = $this->environment[$key] ?? (string) $default;
        if (! is_string($raw) || preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw new InvalidArgumentException($key.' must be an integer');
        }
        $value = (int) $raw;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be between %d and %d', $key, $minimum, $maximum));
        }

        return $value;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $environment = getenv();
    $waiter = new PostgresStartupWaiter(is_array($environment) ? $environment : []);

    exit($waiter->run());
}
