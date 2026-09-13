<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ProbePlatformAccess;
use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\PlatformProbe;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeResult;
use App\Integrations\PlatformAccess\SpotifyProbe;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use App\Integrations\PlatformAccess\YouTubeProbe;
use Illuminate\Console\Application;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProbePlatformAccessTest extends TestCase
{
    private string $sessionPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionPath = tempnam(sys_get_temp_dir(), 'music-map-session-');
    }

    protected function tearDown(): void
    {
        if (isset($this->sessionPath) && is_file($this->sessionPath)) {
            unlink($this->sessionPath);
        }

        parent::tearDown();
    }

    #[DataProvider('validInvocationProvider')]
    public function test_all_provider_and_principal_combinations_return_the_exact_success_contract(
        string $provider,
        string $principal,
    ): void {
        $this->configureTechnicalPrincipal($provider);
        $this->writeTesterSession($provider);
        $this->bindSuccessfulProbe($provider, $principal);

        [$exitCode, $stdout, $stderr] = $this->runInProcess($provider, $principal);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertSame([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $provider,
            'principal' => $principal,
            'status' => 'ok',
            'capabilities' => PlatformAccessProtocol::capabilitiesFor($principal),
            'complete' => true,
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringEndsWith("\n", $stdout);
    }

    public function test_invalid_configuration_stops_before_probe_dispatch(): void
    {
        $called = false;
        $this->bindProbe('spotify', static function () use (&$called): never {
            $called = true;

            throw new RuntimeException('probe must not run');
        });

        [$exitCode, $stdout, $stderr] = $this->runInProcess('spotify', 'technical');

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertFailure($stderr, 'spotify', 'technical', 'invalid-configuration');
        $this->assertFalse($called);
    }

    public function test_invalid_session_stops_before_probe_dispatch(): void
    {
        file_put_contents($this->sessionPath, '{}');
        $called = false;
        $this->bindProbe('youtube', static function () use (&$called): never {
            $called = true;

            throw new RuntimeException('probe must not run');
        });

        [$exitCode, $stdout, $stderr] = $this->runInProcess('youtube', 'tester');

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertFailure($stderr, 'youtube', 'tester', 'invalid-session');
        $this->assertFalse($called);
    }

    public function test_valid_array_input_does_not_depend_on_raw_tokens(): void
    {
        $this->configureTechnicalPrincipal('spotify');
        $this->bindSuccessfulProbe('spotify', 'technical');

        [$exitCode, $stdout, $stderr] = $this->runInProcess('spotify', 'technical');

        $this->assertSame(0, $exitCode);
        $this->assertNotSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    public function test_unexpected_throwable_after_dispatch_is_closed_and_never_logged(): void
    {
        $canary = 'provider-secret-canary';
        $this->configureTechnicalPrincipal('spotify');
        $this->bindProbe('spotify', static fn (): never => throw new RuntimeException($canary));
        Log::spy();

        [$exitCode, $stdout, $stderr] = $this->runInProcess('spotify', 'technical');

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertFailure($stderr, 'spotify', 'technical', 'provider-unavailable');
        $this->assertStringNotContainsString($canary, $stderr);
        Log::shouldNotHaveReceived('emergency');
        Log::shouldNotHaveReceived('alert');
        Log::shouldNotHaveReceived('critical');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('notice');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    #[DataProvider('invalidRawInvocationProvider')]
    public function test_raw_argv_is_rejected_before_symfony_binds_the_command(array $arguments): void
    {
        [$exitCode, $stdout, $stderr] = $this->runSubprocess($arguments);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertFailure($stderr, 'spotify', 'technical', 'invalid-invocation');
        $this->assertStringNotContainsString('The "', $stderr);
    }

    public function test_standard_help_and_other_artisan_commands_keep_standard_behavior(): void
    {
        [$helpExit, $helpStdout, $helpStderr] = $this->runSubprocess([
            'help',
            PlatformAccessProtocol::COMMAND,
            '--no-ansi',
        ]);
        [$listExit, $listStdout, $listStderr] = $this->runSubprocess(['list', '--raw', '--no-ansi']);

        $this->assertSame(0, $helpExit);
        $this->assertStringContainsString('Usage:', $helpStdout);
        $this->assertStringContainsString(PlatformAccessProtocol::COMMAND, $helpStdout);
        $this->assertSame('', $helpStderr);
        $this->assertSame(0, $listExit);
        $this->assertStringContainsString(PlatformAccessProtocol::COMMAND, $listStdout);
        $this->assertSame('', $listStderr);
    }

    public static function validInvocationProvider(): array
    {
        return [
            'Spotify technical' => ['spotify', 'technical'],
            'Spotify tester' => ['spotify', 'tester'],
            'YouTube technical' => ['youtube', 'technical'],
            'YouTube tester' => ['youtube', 'tester'],
        ];
    }

    public static function invalidRawInvocationProvider(): array
    {
        $valid = [
            PlatformAccessProtocol::COMMAND,
            '--provider=spotify',
            '--principal=technical',
            '--write',
            '--format=json',
            '--no-ansi',
            '--no-interaction',
        ];

        return [
            'unknown option before command' => [[
                '--unknown',
                ...$valid,
            ]],
            'unknown option after command' => [[
                ...$valid,
                '--unknown',
            ]],
            'positional token before command' => [[
                'unexpected-position',
                ...$valid,
            ]],
            'unknown option and separate value before command' => [[
                '--unknown',
                'separate-value',
                ...$valid,
            ]],
        ];
    }

    private function runInProcess(string $provider, string $principal): array
    {
        $application = new Application($this->app, $this->app['events'], $this->app->version());
        $application->addCommand(new ProbePlatformAccess($this->sessionPath));
        $tester = new ApplicationTester($application);
        $exitCode = $tester->run([
            'command' => PlatformAccessProtocol::COMMAND,
            '--provider' => $provider,
            '--principal' => $principal,
            '--write' => true,
            '--format' => 'json',
            '--no-ansi' => true,
            '--no-interaction' => true,
        ], [
            'capture_stderr_separately' => true,
            'decorated' => false,
        ]);

        return [$exitCode, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    private function runSubprocess(array $arguments): array
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', ...$arguments],
            dirname(__DIR__, 3),
            timeout: 10,
        );
        $exitCode = $process->run();

        return [$exitCode, $process->getOutput(), $process->getErrorOutput()];
    }

    private function configureTechnicalPrincipal(string $provider): void
    {
        config()->set("services.platform_access.{$provider}.technical", [
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'expected_account_id' => 'expected-account',
            'account_id' => 'expected-account',
            'scopes' => implode(' ', PlatformAccessProtocol::scopesFor($provider)),
        ]);
    }

    private function writeTesterSession(string $provider): void
    {
        file_put_contents($this->sessionPath, json_encode([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => $provider,
            'principal' => 'tester',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'expected_account_id' => 'expected-account',
            'item_uris' => $provider === 'spotify'
                ? [
                    'spotify:track:0123456789ABCDEFGHIJKL',
                    'spotify:track:123456789ABCDEFGHIJKLM',
                    'spotify:track:23456789ABCDEFGHIJKLMN',
                ]
                : ['abcdefghijk', 'bcdefghijkl', 'cdefghijklm'],
            'playlist_id' => 'fixture-playlist',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function bindSuccessfulProbe(string $provider, string $principal): void
    {
        $this->bindProbe(
            $provider,
            static function (TechnicalConfiguration|TesterSession $input) use ($provider, $principal): ProbeResult {
                self::assertSame($provider, $input->provider);
                self::assertSame(
                    $principal === 'technical' ? TechnicalConfiguration::class : TesterSession::class,
                    $input::class,
                );

                return ProbeResult::success($provider, $principal);
            },
        );
    }

    private function bindProbe(string $provider, callable $callback): void
    {
        $probe = new class($callback) implements PlatformProbe
        {
            public function __construct(private $callback) {}

            public function probe(TechnicalConfiguration|TesterSession $input): ProbeResult|ProbeFailure
            {
                return ($this->callback)($input);
            }
        };

        $this->app->instance(
            $provider === 'spotify' ? SpotifyProbe::class : YouTubeProbe::class,
            $probe,
        );
    }

    private function assertFailure(
        string $stderr,
        ?string $provider,
        ?string $principal,
        string $category,
    ): void {
        $this->assertStringEndsWith("\n", $stderr);
        $payload = json_decode($stderr, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'protocol',
            'provider',
            'principal',
            'status',
            'category',
            'correlation_id',
            'complete',
        ], array_keys($payload));
        $this->assertSame($provider, $payload['provider']);
        $this->assertSame($principal, $payload['principal']);
        $this->assertSame('error', $payload['status']);
        $this->assertSame($category, $payload['category']);
        $this->assertFalse($payload['complete']);
    }
}
