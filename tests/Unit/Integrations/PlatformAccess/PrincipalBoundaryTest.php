<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\TechnicalConfiguration;
use App\Integrations\PlatformAccess\TesterSession;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrincipalBoundaryTest extends TestCase
{
    public function test_technical_configuration_does_not_fall_back_to_a_valid_tester_session(): void
    {
        Http::fake();

        $configuration = TechnicalConfiguration::fromArray('spotify', [
            'client_id' => '__REQUIRED_RUNTIME_SECRET__',
            'client_secret' => '__REQUIRED_RUNTIME_SECRET__',
            'refresh_token' => '__REQUIRED_RUNTIME_SECRET__',
            'expected_account_id' => '__REQUIRED_RUNTIME_VALUE__',
            'account_id' => '__REQUIRED_RUNTIME_VALUE__',
            'scopes' => implode(' ', PlatformAccessProtocol::SCOPES['spotify']),
        ]);

        $this->assertInstanceOf(ProbeFailure::class, $configuration);
        $this->assertSame('invalid-configuration', $configuration->category);
        Http::assertNothingSent();
    }

    public function test_tester_session_does_not_read_or_fall_back_to_technical_configuration(): void
    {
        Http::fake();
        config()->set('services.platform_access.spotify.technical', [
            'client_id' => 'technical-client',
            'client_secret' => 'technical-secret',
            'refresh_token' => 'technical-refresh',
            'expected_account_id' => 'technical-account',
            'account_id' => 'technical-account',
            'scopes' => implode(' ', PlatformAccessProtocol::SCOPES['spotify']),
        ]);

        $session = TesterSession::fromJson('spotify', '{}');

        $this->assertInstanceOf(ProbeFailure::class, $session);
        $this->assertSame('invalid-session', $session->category);
        Http::assertNothingSent();
    }
}
