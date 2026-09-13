<?php

namespace App\Console\Commands;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeInvocation;
use App\Integrations\PlatformAccess\ProbeResult;
use App\Integrations\PlatformAccess\SpotifyProbe;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use App\Integrations\PlatformAccess\YouTubeProbe;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ProbePlatformAccess extends Command
{
    protected $signature = 'platform-access:probe
        {--provider= : spotify or youtube}
        {--principal= : technical or tester}
        {--write : Require the complete acceptance matrix}
        {--format= : Output format; must be json}';

    protected $description = 'Verify the public platform-access contract';

    public function __construct(
        private readonly string $sessionPath = PlatformAccessProtocol::SESSION_PATH,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = PlatformAccessProtocol::isProvider($this->option('provider'))
            ? $this->option('provider')
            : null;
        $principal = PlatformAccessProtocol::isPrincipal($this->option('principal'))
            ? $this->option('principal')
            : null;

        try {
            $invocation = ProbeInvocation::fromInput($this->input);

            if ($invocation instanceof ProbeFailure) {
                return $this->render($invocation);
            }

            $input = $invocation->principal === 'technical'
                ? TechnicalConfiguration::fromArray(
                    $invocation->provider,
                    config("services.platform_access.{$invocation->provider}.technical", []),
                )
                : TesterSession::fromFile($invocation->provider, $this->sessionPath);

            if ($input instanceof ProbeFailure) {
                return $this->render($input);
            }

            $probe = app($invocation->provider === 'spotify'
                ? SpotifyProbe::class
                : YouTubeProbe::class);

            return $this->render($probe->probe($input));
        } catch (Throwable) {
            return $this->render(ProbeFailure::make(
                'provider-unavailable',
                $provider,
                $principal,
            ));
        }
    }

    private function render(ProbeResult|ProbeFailure $result): int
    {
        if ($result instanceof ProbeResult) {
            $this->output->write($result->stdout(), false, OutputInterface::OUTPUT_RAW);
        } else {
            $baseOutput = method_exists($this->output, 'getOutput')
                ? $this->output->getOutput()
                : $this->output;
            $output = $baseOutput instanceof ConsoleOutputInterface
                ? $baseOutput->getErrorOutput()
                : $baseOutput;
            $output->write($result->stderr(), false, OutputInterface::OUTPUT_RAW);
        }

        return $result->exitCode();
    }
}
