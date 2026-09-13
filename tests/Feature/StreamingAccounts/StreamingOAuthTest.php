<?php

namespace Tests\Feature\StreamingAccounts;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StreamingOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_page_and_oauth_routes_require_verified_authentication(): void
    {
        $this->get('/integrations')->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get('/integrations')->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->create())
            ->get('/integrations')
            ->assertOk()
            ->assertSee('Integracje streamingowe');
    }

    public function test_callback_consumes_state_once_and_persists_only_encrypted_refresh_token(): void
    {
        $user = User::factory()->create();
        $gateway = new FlowFakeGateway(StreamingProvider::Spotify);
        $this->app->instance('streaming-oauth.spotify', $gateway);

        $connect = $this->actingAs($user)->post(route('integrations.connect', 'spotify'));
        $connect->assertRedirectContains('https://provider.example/authorize');
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $session = session()->all();

        $this->assertArrayNotHasKey('code-canary', $session);
        $this->assertStringNotContainsString($query['state'], serialize($session));

        $callback = $this->get(route('integrations.callback', [
            'provider' => 'spotify',
            'state' => $query['state'],
            'code' => 'code-canary',
        ]));

        $callback->assertRedirect(route('integrations.index'));
        $callback->assertSessionHas('status');
        $account = $user->streamingAccounts()->firstOrFail();
        $rawToken = DB::table('streaming_accounts')->where('id', $account->id)->value('refresh_token');
        $this->assertSame('refresh-canary', $account->refresh_token);
        $this->assertStringNotContainsString('refresh-canary', $rawToken);
        $this->assertStringNotContainsString('access-canary', serialize(session()->all()));
        $this->assertStringNotContainsString('code-canary', serialize(session()->all()));

        $this->get(route('integrations.callback', [
            'provider' => 'spotify',
            'state' => $query['state'],
            'code' => 'code-canary',
        ]))->assertSessionHas('error');
        $this->assertSame(1, $gateway->exchanges);
    }

    public function test_cancel_and_expired_state_end_before_provider_request(): void
    {
        $user = User::factory()->create();
        $gateway = new FlowFakeGateway(StreamingProvider::Spotify);
        $this->app->instance('streaming-oauth.spotify', $gateway);

        $this->actingAs($user)->withSession([
            'streaming_oauth.attempts' => [[
                'state_hash' => hash('sha256', 'expired-state'),
                'provider' => 'spotify',
                'purpose' => 'link',
                'user_id' => $user->id,
                'created_at' => now()->subMinutes(11)->getTimestamp(),
            ]],
        ])->get(route('integrations.callback', [
            'provider' => 'spotify',
            'state' => 'expired-state',
            'code' => 'code-canary',
        ]))->assertSessionHas('error');

        $connect = $this->post(route('integrations.connect', 'spotify'));
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get(route('integrations.callback', [
            'provider' => 'spotify',
            'state' => $query['state'],
            'error' => 'access_denied',
        ]))->assertSessionHas('error');

        $this->assertSame(0, $gateway->exchanges);
    }

    public function test_verify_resolves_only_owned_accounts_and_uses_one_unknown_rate_key(): void
    {
        $user = User::factory()->create();
        $foreign = StreamingAccount::factory()->spotify()->create();
        $gateway = new FlowFakeGateway(StreamingProvider::Spotify);
        $this->app->instance('streaming-oauth.spotify', $gateway);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $id = $attempt % 2 === 0 ? 999999 : $foreign->id;
            $this->actingAs($user)
                ->post(route('integrations.accounts.verify', $id))
                ->assertRedirect(route('integrations.index'));
        }

        $this->post(route('integrations.accounts.verify', 888888))->assertTooManyRequests();
        $this->assertSame(0, $gateway->refreshes);
    }

    public function test_limiter_keeps_separate_provider_budgets(): void
    {
        $user = User::factory()->create();
        $this->app->instance('streaming-oauth.spotify', new FlowFakeGateway(StreamingProvider::Spotify));
        $this->app->instance('streaming-oauth.youtube', new FlowFakeGateway(StreamingProvider::YouTube));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->actingAs($user)->post(route('integrations.connect', 'spotify'))->assertRedirect();
        }

        $this->post(route('integrations.connect', 'youtube'))->assertRedirectContains('provider.example');
    }
}

final class FlowFakeGateway implements StreamingOAuthGateway
{
    public int $exchanges = 0;

    public int $refreshes = 0;

    public function __construct(private readonly StreamingProvider $provider) {}

    public function authorizationUrl(string $state): string
    {
        return 'https://provider.example/authorize?state='.rawurlencode($state);
    }

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure
    {
        $this->exchanges++;

        return new StreamingGrant('access-canary', 'refresh-canary', $this->provider->requiredScopes());
    }

    public function refresh(string $refreshToken): StreamingGrant|StreamingOAuthFailure
    {
        $this->refreshes++;

        return new StreamingGrant('verify-access-canary', null, $this->provider->requiredScopes());
    }

    public function identity(string $accessToken): StreamingIdentity|StreamingOAuthFailure
    {
        return new StreamingIdentity('provider-account-canary', 'Canary account');
    }

    public function revoke(string $token): ?StreamingOAuthFailure
    {
        return null;
    }
}
