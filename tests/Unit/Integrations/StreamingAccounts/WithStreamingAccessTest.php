<?php

namespace Tests\Unit\Integrations\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Actions\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess as WithStreamingAccessContract;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WithStreamingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_port_delivers_stable_identity_and_rotates_by_credential_version(): void
    {
        $account = $this->account();
        $gateway = new AccessFakeGateway(fn () => new StreamingGrant(
            'access-canary',
            'replacement-canary',
            StreamingProvider::Spotify->requiredScopes(),
        ));
        $this->app->instance('streaming-oauth.spotify', $gateway);
        $contextSeen = null;

        $result = $this->app->make(WithStreamingAccessContract::class)->handle(
            $account->user,
            $account,
            array_reverse(StreamingProvider::Spotify->requiredScopes()),
            function (StreamingAccessContext $context) use (&$contextSeen): string {
                $contextSeen = $context;

                return 'callback-result';
            },
        );

        $this->assertTrue($result->successful);
        $this->assertSame('callback-result', $result->value);
        $this->assertSame('stable-account', $contextSeen->providerAccountId);
        $this->assertSame('access-canary', $contextSeen->accessToken);
        $this->assertSame(2, $account->fresh()->credential_version);
        $this->assertSame('replacement-canary', $account->fresh()->refresh_token);
        $this->assertStringNotContainsString(
            'replacement-canary',
            DB::table('streaming_accounts')->where('id', $account->id)->value('refresh_token'),
        );
    }

    public function test_no_replacement_refresh_cannot_run_callback_after_unlink_relink(): void
    {
        $account = $this->account();
        $gateway = new AccessFakeGateway(function () use ($account) {
            $account->delete();
            $this->account($account->user_id, 1);

            return new StreamingGrant('late-access', null, StreamingProvider::Spotify->requiredScopes());
        });
        $this->app->instance('streaming-oauth.spotify', $gateway);
        $called = false;

        $result = $this->app->make(WithStreamingAccess::class)->handle(
            $account->user,
            $account,
            StreamingProvider::Spotify->requiredScopes(),
            function () use (&$called): void {
                $called = true;
            },
        );

        $this->assertSame(StreamingAccessFailure::StaleCredential, $result->failure);
        $this->assertFalse($called);
    }

    public function test_late_invalid_grant_does_not_clear_relinked_credential(): void
    {
        $account = $this->account();
        $gateway = new AccessFakeGateway(function () use ($account) {
            StreamingAccount::query()->whereKey($account->id)->update([
                'refresh_token' => Crypt::encryptString('new-canary'),
                'credential_version' => 2,
            ]);

            return StreamingOAuthFailure::AuthorizationDenied;
        });
        $this->app->instance('streaming-oauth.spotify', $gateway);

        $result = $this->app->make(WithStreamingAccess::class)->handle(
            $account->user,
            $account,
            StreamingProvider::Spotify->requiredScopes(),
            fn () => true,
        );

        $this->assertSame(StreamingAccessFailure::StaleCredential, $result->failure);
        $this->assertSame(2, $account->fresh()->credential_version);
        $this->assertSame('new-canary', $account->fresh()->refresh_token);
    }

    public function test_missing_scope_stops_before_refresh(): void
    {
        $account = $this->account();
        $account->scopes = ['user-read-private'];
        $account->save();
        $gateway = new AccessFakeGateway(fn () => new StreamingGrant('unused', null, []));
        $this->app->instance('streaming-oauth.spotify', $gateway);

        $result = $this->app->make(WithStreamingAccess::class)->handle(
            $account->user,
            $account,
            StreamingProvider::Spotify->requiredScopes(),
            fn () => true,
        );

        $this->assertSame(StreamingAccessFailure::MissingScope, $result->failure);
        $this->assertSame(0, $gateway->refreshes);
    }

    private function account(?int $userId = null, int $version = 1): StreamingAccount
    {
        return StreamingAccount::factory()->spotify()->create([
            ...($userId === null ? [] : ['user_id' => $userId]),
            'provider_account_id' => 'stable-account',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
            'credential_version' => $version,
        ]);
    }
}

final class AccessFakeGateway implements StreamingOAuthGateway
{
    public int $refreshes = 0;

    public function __construct(private readonly Closure $refreshResult) {}

    public function authorizationUrl(string $state): string
    {
        return 'https://example.test';
    }

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure
    {
        return StreamingOAuthFailure::TemporarilyUnavailable;
    }

    public function refresh(string $refreshToken): StreamingGrant|StreamingOAuthFailure
    {
        $this->refreshes++;

        return ($this->refreshResult)();
    }

    public function identity(string $accessToken): StreamingIdentity|StreamingOAuthFailure
    {
        return StreamingOAuthFailure::TemporarilyUnavailable;
    }

    public function revoke(string $token): ?StreamingOAuthFailure
    {
        return null;
    }
}
