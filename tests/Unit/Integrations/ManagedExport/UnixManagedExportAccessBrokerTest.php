<?php

namespace Tests\Unit\Integrations\ManagedExport;

use App\Integrations\ManagedExport\ManagedExportAccessException;
use App\Integrations\ManagedExport\UnixManagedExportAccessBroker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnixManagedExportAccessBrokerTest extends TestCase
{
    private const OPERATION_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function test_it_sends_the_exact_v1_request_and_returns_ephemeral_access(): void
    {
        $observed = null;
        $broker = new UnixManagedExportAccessBroker('/unused', function (string $request) use (&$observed): string {
            $observed = $request;

            return $this->success();
        });

        $access = $broker->acquire('spotify', self::OPERATION_ID);

        $this->assertSame(
            '{"protocol":"music-map.managed-export.v1","provider":"spotify","operation_id":"'.self::OPERATION_ID."\"}\n",
            $observed,
        );
        $this->assertSame('spotify', $access->provider);
        $this->assertSame('synthetic-access-token', $access->accessToken);
        $this->assertSame(self::OPERATION_ID, $access->operationId);
    }

    public function test_it_maps_only_the_closed_sanitized_failure(): void
    {
        $secret = 'provider-body-secret-canary';
        $broker = new UnixManagedExportAccessBroker('/unused', fn (): string => json_encode([
            'protocol' => UnixManagedExportAccessBroker::PROTOCOL,
            'status' => 'error',
            'provider' => 'spotify',
            'operation_id' => self::OPERATION_ID,
            'category' => 'provider-unavailable',
            'retryable' => true,
            'retry_after' => 2,
        ], JSON_THROW_ON_ERROR)."\n");

        try {
            $broker->acquire('spotify', self::OPERATION_ID);
            $this->fail('Expected managed-export failure.');
        } catch (ManagedExportAccessException $failure) {
            $this->assertSame('provider-unavailable', $failure->category);
            $this->assertTrue($failure->retryable);
            $this->assertSame(2, $failure->retryAfter);
            $this->assertStringNotContainsString($secret, (string) $failure);
        }
    }

    #[DataProvider('invalidResponses')]
    public function test_it_fails_closed_for_non_contract_responses(string $response): void
    {
        $broker = new UnixManagedExportAccessBroker('/unused', fn (): string => $response);

        try {
            $broker->acquire('spotify', self::OPERATION_ID);
            $this->fail('Expected invalid broker response.');
        } catch (ManagedExportAccessException $failure) {
            $this->assertSame('internal-failure', $failure->category);
            $this->assertStringNotContainsString('secret-canary', (string) $failure);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidResponses(): array
    {
        $valid = [
            'protocol' => UnixManagedExportAccessBroker::PROTOCOL,
            'status' => 'ok',
            'provider' => 'spotify',
            'operation_id' => self::OPERATION_ID,
            'token_type' => 'Bearer',
            'access_token' => 'secret-canary',
            'expires_at' => '2099-01-01T00:00:00+00:00',
        ];

        return [
            'missing newline' => [json_encode($valid, JSON_THROW_ON_ERROR)],
            'probe protocol' => [json_encode([
                ...$valid,
                'protocol' => 'music-map.platform-access.v1',
            ], JSON_THROW_ON_ERROR)."\n"],
            'extra field' => [json_encode([
                ...$valid,
                'refresh_token' => 'secret-canary',
            ], JSON_THROW_ON_ERROR)."\n"],
            'wrong operation' => [json_encode([
                ...$valid,
                'operation_id' => '123e4567-e89b-42d3-a456-426614174001',
            ], JSON_THROW_ON_ERROR)."\n"],
            'control in token' => [json_encode([
                ...$valid,
                'access_token' => "secret\ncanary",
            ], JSON_THROW_ON_ERROR)."\n"],
            'expired lease' => [json_encode([
                ...$valid,
                'expires_at' => '2020-01-01T00:00:00+00:00',
            ], JSON_THROW_ON_ERROR)."\n"],
            'relative expiration' => [json_encode([
                ...$valid,
                'expires_at' => 'tomorrow',
            ], JSON_THROW_ON_ERROR)."\n"],
            'offset-free expiration' => [json_encode([
                ...$valid,
                'expires_at' => '2099-01-01T00:00:00',
            ], JSON_THROW_ON_ERROR)."\n"],
            'invalid calendar expiration' => [json_encode([
                ...$valid,
                'expires_at' => '2099-02-30T00:00:00+00:00',
            ], JSON_THROW_ON_ERROR)."\n"],
            'invalid timezone expiration' => [json_encode([
                ...$valid,
                'expires_at' => '2099-01-01T00:00:00+24:00',
            ], JSON_THROW_ON_ERROR)."\n"],
        ];
    }

    public function test_it_rejects_invalid_input_before_opening_transport(): void
    {
        $calls = 0;
        $broker = new UnixManagedExportAccessBroker('/unused', function () use (&$calls): string {
            $calls++;

            return $this->success();
        });

        foreach ([['tidal', self::OPERATION_ID], ['spotify', 'not-a-uuid']] as [$provider, $operationId]) {
            try {
                $broker->acquire($provider, $operationId);
                $this->fail('Expected invalid request.');
            } catch (ManagedExportAccessException $failure) {
                $this->assertSame('invalid-request', $failure->category);
            }
        }
        $this->assertSame(0, $calls);
    }

    private function success(): string
    {
        return json_encode([
            'access_token' => 'synthetic-access-token',
            'expires_at' => '2099-01-01T00:00:00+00:00',
            'operation_id' => self::OPERATION_ID,
            'protocol' => UnixManagedExportAccessBroker::PROTOCOL,
            'provider' => 'spotify',
            'status' => 'ok',
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_versioned_contract_fixtures_are_accepted_without_probe_fallback(): void
    {
        $fixture = file_get_contents(__DIR__.'/../../../Fixtures/music-map-managed-export-success.json');
        $this->assertIsString($fixture);
        $broker = new UnixManagedExportAccessBroker('/unused', fn (): string => $fixture);

        $access = $broker->acquire('spotify', self::OPERATION_ID);

        $this->assertSame('synthetic-access-token', $access->accessToken);
        $request = json_decode(
            (string) file_get_contents(__DIR__.'/../../../Fixtures/music-map-managed-export-request.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(UnixManagedExportAccessBroker::PROTOCOL, $request['protocol']);
        $this->assertNotSame('music-map.platform-access.v1', $request['protocol']);
    }
}
