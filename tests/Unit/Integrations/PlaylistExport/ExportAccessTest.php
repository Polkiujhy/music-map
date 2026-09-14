<?php

namespace Tests\Unit\Integrations\PlaylistExport;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedExport\Contracts\ManagedExportAccessBroker;
use App\Integrations\ManagedExport\Data\ManagedExportAccess;
use App\Integrations\PlaylistExport\Actions\WithLinkedExportAccess;
use App\Integrations\PlaylistExport\Actions\WithManagedExportAccess;
use App\Integrations\PlaylistExport\Contracts\ExportMutationGuard;
use App\Integrations\PlaylistExport\PlaylistWriteFailure;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Models\ExportOperation;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_access_snapshots_the_post_rotation_version_and_guards_every_mutation(): void
    {
        $account = StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'linked-account',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
            'credential_version' => 1,
        ]);
        $operation = ExportOperation::factory()->create([
            'user_id' => $account->user_id,
            'destination_type' => ExportDestinationType::Linked,
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'linked-account',
            'streaming_account_id' => $account->id,
        ]);
        $access = new ExportAccessStreamingFake(function () use ($account): void {
            $account->forceFill(['credential_version' => 2])->save();
        });
        $seenToken = null;

        $failure = (new WithLinkedExportAccess($access))->handle(
            $operation,
            function (string $token, ExportMutationGuard $guard) use (&$seenToken, $account): ?PlaylistWriteFailure {
                $seenToken = $token;
                $this->assertNull($guard->failure());

                $account->forceFill(['credential_version' => 3])->save();

                return $guard->failure();
            },
        );

        $this->assertSame('ephemeral-access', $seenToken);
        $this->assertSame(PlaylistWriteFailure::StaleCredential, $failure);
    }

    public function test_linked_retry_accepts_a_relinked_account_only_for_the_same_provider_identity(): void
    {
        $operation = ExportOperation::factory()->create([
            'destination_type' => ExportDestinationType::Linked,
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'stable-identity',
            'streaming_account_id' => null,
        ]);
        StreamingAccount::factory()->spotify()->create([
            'user_id' => $operation->user_id,
            'provider_account_id' => 'stable-identity',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);

        $failure = (new WithLinkedExportAccess(new ExportAccessStreamingFake))->handle(
            $operation,
            fn (string $token, ExportMutationGuard $guard): ?PlaylistWriteFailure => $guard->failure(),
        );

        $this->assertNull($failure);

        StreamingAccount::query()->delete();
        StreamingAccount::factory()->spotify()->create([
            'user_id' => $operation->user_id,
            'provider_account_id' => 'different-identity',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);

        $this->assertSame(
            PlaylistWriteFailure::ReconnectRequired,
            (new WithLinkedExportAccess(new ExportAccessStreamingFake))->handle(
                $operation,
                fn (): null => null,
            ),
        );
    }

    public function test_managed_access_uses_the_published_broker_and_never_exposes_a_refresh_token(): void
    {
        $operation = ExportOperation::factory()->create([
            'destination_type' => ExportDestinationType::Managed,
            'target_provider' => StreamingProvider::YouTube,
            'target_account_id' => 'managed-channel',
        ]);
        $broker = new ExportAccessManagedBrokerFake;
        $seen = null;

        $failure = (new WithManagedExportAccess($broker))->handle(
            $operation,
            function (string $token, ExportMutationGuard $guard) use (&$seen): ?PlaylistWriteFailure {
                $seen = [$token, $guard->failure()];

                return null;
            },
        );

        $this->assertNull($failure);
        $this->assertSame(['managed-ephemeral', null], $seen);
        $this->assertSame([$operation->target_provider->value, $operation->operation_id], $broker->request);
    }

    public function test_unvalidated_managed_transport_failure_is_closed_and_not_retryable(): void
    {
        $operation = ExportOperation::factory()->create([
            'destination_type' => ExportDestinationType::Managed,
        ]);
        $broker = new ExportAccessManagedBrokerFake(new \RuntimeException('transport details'));

        $this->assertSame(
            PlaylistWriteFailure::InvalidResponse,
            (new WithManagedExportAccess($broker))->handle($operation, fn (): null => null),
        );
    }

    public function test_managed_access_rejects_a_token_below_the_operation_budget_and_safety_margin(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $operation = ExportOperation::factory()->create([
            'destination_type' => ExportDestinationType::Managed,
        ]);
        $broker = new ExportAccessManagedBrokerFake(
            expiresAt: $now->modify('+389 seconds'),
        );
        $callbackCalled = false;

        $failure = (new WithManagedExportAccess($broker, static fn (): DateTimeImmutable => $now))->handle(
            $operation,
            function () use (&$callbackCalled): null {
                $callbackCalled = true;

                return null;
            },
        );

        $this->assertSame(PlaylistWriteFailure::TemporaryFailure, $failure);
        $this->assertFalse($callbackCalled);
    }

    public function test_managed_access_accepts_a_token_at_the_minimum_validity_boundary(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $operation = ExportOperation::factory()->create([
            'destination_type' => ExportDestinationType::Managed,
        ]);
        $broker = new ExportAccessManagedBrokerFake(
            expiresAt: $now->modify('+390 seconds'),
        );
        $callbackCalled = false;

        $failure = (new WithManagedExportAccess($broker, static fn (): DateTimeImmutable => $now))->handle(
            $operation,
            function () use (&$callbackCalled): null {
                $callbackCalled = true;

                return null;
            },
        );

        $this->assertNull($failure);
        $this->assertTrue($callbackCalled);
    }
}

final readonly class ExportAccessStreamingFake implements WithStreamingAccess
{
    public function __construct(private ?Closure $beforeCallback = null) {}

    public function handle(
        User $owner,
        StreamingAccount $account,
        array $requiredScopes,
        Closure $callback,
    ): StreamingAccessResult {
        if ($this->beforeCallback instanceof Closure) {
            ($this->beforeCallback)();
        }
        $callback(new StreamingAccessContext($account->provider, $account->provider_account_id, 'ephemeral-access'));

        return StreamingAccessResult::success();
    }
}

final class ExportAccessManagedBrokerFake implements ManagedExportAccessBroker
{
    /** @var null|array{string, string} */
    public ?array $request = null;

    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function acquire(string $provider, string $operationId): ManagedExportAccess
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
        $this->request = [$provider, $operationId];

        return new ManagedExportAccess(
            $provider,
            'managed-ephemeral',
            $this->expiresAt ?? new DateTimeImmutable('+10 minutes'),
            $operationId,
        );
    }
}
