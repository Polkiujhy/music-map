<?php

namespace Tests\Unit\Integrations\PlatformAccess;

use App\Integrations\PlatformAccess\ProbeFailure;
use App\Integrations\PlatformAccess\ProviderFailureMapper;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderFailureMapperTest extends TestCase
{
    #[DataProvider('mappedFailures')]
    public function test_all_provider_facing_categories_are_closed_and_deterministic(
        string $category,
        callable $map,
    ): void {
        $canary = 'provider-controlled-secret-canary';
        $failure = $map($canary);

        $this->assertInstanceOf(ProbeFailure::class, $failure);
        $this->assertSame($category, $failure->category);
        $this->assertSame(1, $failure->exitCode());
        $this->assertStringNotContainsString($canary, $failure->stdout());
        $this->assertStringNotContainsString($canary, $failure->stderr());
        $this->assertSame('', $failure->stdout());
        $this->assertSame([
            'protocol',
            'provider',
            'principal',
            'status',
            'category',
            'correlation_id',
            'complete',
        ], array_keys(json_decode($failure->stderr(), true, flags: JSON_THROW_ON_ERROR)));
    }

    public static function mappedFailures(): array
    {
        return [
            'provider unavailable' => [
                'provider-unavailable',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::transport('spotify', 'technical'),
            ],
            'provider response invalid' => [
                'provider-response-invalid',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::invalidResponse('youtube', 'tester'),
            ],
            'authorization denied' => [
                'authorization-denied',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'spotify',
                    'technical',
                    ProviderFailureMapper::STAGE_REFRESH,
                    self::response(400, ['error' => 'invalid_grant', 'description' => $canary]),
                ),
            ],
            'account mismatch' => [
                'account-mismatch',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::accountMismatch('spotify', 'tester'),
            ],
            'scope mismatch' => [
                'scope-mismatch',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::scopeMismatch('youtube', 'technical'),
            ],
            'account requirement failed' => [
                'account-requirement-failed',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'spotify',
                    'tester',
                    ProviderFailureMapper::STAGE_INSERT,
                    self::response(403, ['reason' => 'PREMIUM_REQUIRED', 'message' => $canary]),
                ),
            ],
            'resource access denied' => [
                'resource-access-denied',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'youtube',
                    'tester',
                    ProviderFailureMapper::STAGE_FIXTURE,
                    self::response(404, ['error' => ['message' => $canary]]),
                ),
            ],
            'fixture invalid' => [
                'fixture-invalid',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'youtube',
                    'tester',
                    ProviderFailureMapper::STAGE_INSERT,
                    self::response(400, ['error' => ['errors' => [[
                        'reason' => 'videoNotFound',
                        'message' => $canary,
                    ]]]]),
                ),
            ],
            'rate limited' => [
                'rate-limited',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'spotify',
                    'technical',
                    ProviderFailureMapper::STAGE_IDENTITY,
                    self::response(429, ['message' => $canary]),
                ),
            ],
            'quota exceeded' => [
                'quota-exceeded',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::response(
                    'spotify',
                    'tester',
                    ProviderFailureMapper::STAGE_INSERT,
                    self::response(429, ['reason' => 'QUOTA_EXCEEDED', 'message' => $canary]),
                ),
            ],
            'rotation required' => [
                'refresh-token-rotation-required',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::rotationRequired('spotify', 'tester'),
            ],
            'cleanup failed' => [
                'cleanup-failed',
                fn (string $canary): ProbeFailure => ProviderFailureMapper::cleanupFailed('youtube', 'tester'),
            ],
        ];
    }

    public function test_ambiguous_four_hundred_response_does_not_guess_from_provider_text(): void
    {
        $canary = 'quota exceeded invalid token premium required';

        $failure = ProviderFailureMapper::response(
            'spotify',
            'technical',
            ProviderFailureMapper::STAGE_IDENTITY,
            self::response(400, ['error' => ['message' => $canary]]),
        );

        $this->assertSame('provider-unavailable', $failure->category);
        $this->assertStringNotContainsString($canary, $failure->stderr());
    }

    public function test_youtube_daily_limit_reason_is_quota_exceeded(): void
    {
        $failure = ProviderFailureMapper::response(
            'youtube',
            'tester',
            ProviderFailureMapper::STAGE_INSERT,
            self::response(403, ['error' => ['errors' => [[
                'reason' => 'dailyLimitExceeded',
            ]]]]),
        );

        $this->assertSame('quota-exceeded', $failure->category);
    }

    private static function response(int $status, array $body): Response
    {
        return new Response(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        ));
    }
}
