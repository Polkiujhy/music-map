<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\PlatformAccessProtocol;
use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProbeResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProbeOutputTest extends TestCase
{
    #[DataProvider('successfulPrincipals')]
    public function test_success_has_exact_schema_capabilities_stream_and_newline(string $principal, array $capabilities): void
    {
        $result = ProbeResult::success('spotify', $principal);
        $output = $result->stdout();

        $this->assertSame(0, $result->exitCode());
        $this->assertSame('', $result->stderr());
        $this->assertStringEndsWith("\n", $output);
        $this->assertSame([
            'protocol' => PlatformAccessProtocol::VERSION,
            'provider' => 'spotify',
            'principal' => $principal,
            'status' => 'ok',
            'capabilities' => $capabilities,
            'complete' => true,
        ], json_decode($output, true, flags: JSON_THROW_ON_ERROR));
    }

    public static function successfulPrincipals(): array
    {
        return [
            'technical' => ['technical', ['identity', 'read', 'refresh-token-exchange']],
            'tester' => ['tester', ['cleanup', 'identity', 'read', 'refresh-token-exchange', 'write']],
        ];
    }

    #[DataProvider('failureCategories')]
    public function test_failure_has_exact_schema_stream_newline_and_exit_code(string $category, int $exitCode): void
    {
        $failure = ProbeFailure::make($category, 'youtube', 'tester');
        $output = $failure->stderr();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($exitCode, $failure->exitCode());
        $this->assertSame('', $failure->stdout());
        $this->assertStringEndsWith("\n", $output);
        $this->assertLessThanOrEqual(1024, strlen($output));
        $this->assertSame([
            'protocol',
            'provider',
            'principal',
            'status',
            'category',
            'correlation_id',
            'complete',
        ], array_keys($decoded));
        $this->assertSame('error', $decoded['status']);
        $this->assertSame($category, $decoded['category']);
        $this->assertFalse($decoded['complete']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $decoded['correlation_id'],
        );
    }

    public static function failureCategories(): array
    {
        return array_map(
            static fn (int $exitCode, string $category): array => [$category, $exitCode],
            PlatformAccessProtocol::FAILURE_EXIT_CODES,
            array_keys(PlatformAccessProtocol::FAILURE_EXIT_CODES),
        );
    }

    public function test_only_invalid_invocation_may_have_nullable_identity_fields(): void
    {
        $failure = ProbeFailure::make('invalid-invocation', null, null);

        $this->assertNull(json_decode($failure->stderr(), true, flags: JSON_THROW_ON_ERROR)['provider']);

        $this->expectException(InvalidArgumentException::class);
        ProbeFailure::make('invalid-session', null, 'tester');
    }

    public function test_each_failure_generates_its_own_uuid_v4(): void
    {
        $first = ProbeFailure::make('provider-unavailable', 'spotify', 'technical');
        $second = ProbeFailure::make('provider-unavailable', 'spotify', 'technical');

        $this->assertNotSame($first->correlationId, $second->correlationId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first->correlationId,
        );
    }
}
