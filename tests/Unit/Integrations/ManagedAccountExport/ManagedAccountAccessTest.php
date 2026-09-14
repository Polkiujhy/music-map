<?php

namespace Tests\Unit\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Contracts\WithManagedAccountAccess as WithManagedAccountAccessContract;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\Data\ManagedExportAccess;
use App\Integrations\ManagedExport\ManagedExportAccessException;
use DateTimeImmutable;
use Tests\TestCase;

class ManagedAccountAccessTest extends TestCase
{
    private const OPERATION_ID = '123e4567-e89b-42d3-a456-426614174000';

    public function test_access_is_exposed_only_inside_callback_after_exact_identity_and_scope_validation(): void
    {
        config()->set('services.managed_export.providers.spotify', [
            'account_id' => 'technical-owner',
            'scopes' => implode(' ', StreamingProvider::Spotify->requiredScopes()),
        ]);
        $broker = new FakeBroker;
        $this->app->instance(ManagedExportAccessBroker::class, $broker);
        $access = $this->app->make(WithManagedAccountAccessContract::class);
        $observed = null;

        $result = $access->handle(
            StreamingProvider::Spotify,
            self::OPERATION_ID,
            'technical-owner',
            StreamingProvider::Spotify->requiredScopes(),
            function (ManagedAccessContext $context) use (&$observed): void {
                $observed = [$context->provider, $context->providerAccountId, $context->accessToken, $context->operationId];
            },
        );

        $this->assertTrue($result->successful);
        $this->assertSame([StreamingProvider::Spotify, 'technical-owner', 'secret-access-canary', self::OPERATION_ID], $observed);
        $this->assertSame(1, $broker->calls);
        $this->assertArrayNotHasKey('accessToken', get_object_vars($result));
    }

    public function test_mismatch_fails_before_broker_without_linked_account_or_probe_fallback(): void
    {
        config()->set('services.managed_export.providers.spotify', [
            'account_id' => 'different-owner',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $broker = new FakeBroker;
        $this->app->instance(ManagedExportAccessBroker::class, $broker);
        $access = $this->app->make(WithManagedAccountAccessContract::class);

        $identity = $access->handle(StreamingProvider::Spotify, self::OPERATION_ID, 'technical-owner', StreamingProvider::Spotify->requiredScopes(), fn () => null);
        $scope = $access->handle(StreamingProvider::Spotify, self::OPERATION_ID, 'different-owner', ['playlist-modify-private'], fn () => null);

        $this->assertSame(ManagedExportFailureCode::AccountMismatch, $identity->failure);
        $this->assertSame(ManagedExportFailureCode::RequiredScopeMissing, $scope->failure);
        $this->assertSame(0, $broker->calls);
    }

    public function test_published_broker_rotation_failure_is_closed_and_secret_free(): void
    {
        config()->set('services.managed_export.providers.youtube', [
            'account_id' => 'channel-owner',
            'scopes' => StreamingProvider::YouTube->requiredScopes(),
        ]);
        $broker = new FakeBroker('rotation-recovery-required');
        $this->app->instance(ManagedExportAccessBroker::class, $broker);
        $access = $this->app->make(WithManagedAccountAccessContract::class);

        $result = $access->handle(StreamingProvider::YouTube, self::OPERATION_ID, 'channel-owner', StreamingProvider::YouTube->requiredScopes(), fn () => $this->fail('Callback must not run.'));

        $this->assertSame(ManagedExportFailureCode::RefreshRotationRequired, $result->failure);
        $this->assertStringNotContainsString('secret-access-canary', serialize($result));
    }

    public function test_published_broker_retry_decision_and_delay_are_preserved(): void
    {
        config()->set('services.managed_export.providers.youtube', [
            'account_id' => 'channel-owner',
            'scopes' => StreamingProvider::YouTube->requiredScopes(),
        ]);
        $this->app->instance(
            ManagedExportAccessBroker::class,
            new FakeBroker('provider-unavailable', retryable: true, retryAfter: 17),
        );

        $result = $this->app->make(WithManagedAccountAccessContract::class)->handle(
            StreamingProvider::YouTube,
            self::OPERATION_ID,
            'channel-owner',
            StreamingProvider::YouTube->requiredScopes(),
            fn () => $this->fail('Callback must not run.'),
        );

        $this->assertSame(ManagedExportFailureCode::TransportUnavailable, $result->failure);
        $this->assertTrue($result->retryable);
        $this->assertSame(17, $result->retryAfter);
    }

    public function test_unexpected_callback_failure_is_not_disguised_as_a_transport_result(): void
    {
        config()->set('services.managed_export.providers.spotify', [
            'account_id' => 'technical-owner',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $this->app->instance(ManagedExportAccessBroker::class, new FakeBroker);
        $access = $this->app->make(WithManagedAccountAccessContract::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('callback-canary');

        $access->handle(
            StreamingProvider::Spotify,
            self::OPERATION_ID,
            'technical-owner',
            StreamingProvider::Spotify->requiredScopes(),
            fn () => throw new \RuntimeException('callback-canary'),
        );
    }
}

final class FakeBroker implements ManagedExportAccessBroker
{
    public int $calls = 0;

    public function __construct(
        private readonly ?string $failure = null,
        private readonly bool $retryable = false,
        private readonly ?int $retryAfter = null,
    ) {}

    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        $this->calls++;
        if ($this->failure !== null) {
            throw new ManagedExportAccessException($this->failure, $this->retryable, $this->retryAfter);
        }

        return new ManagedExportAccess($provider, 'secret-access-canary', new DateTimeImmutable('+5 minutes'), $operationId);
    }
}
