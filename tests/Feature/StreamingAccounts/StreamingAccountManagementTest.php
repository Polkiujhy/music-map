<?php

namespace Tests\Feature\StreamingAccounts;

use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\AuthIdentity;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StreamingAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_page_renders_two_safe_account_states_and_post_forms(): void
    {
        $user = User::factory()->create();
        $spotify = StreamingAccount::factory()->for($user)->spotify()->create([
            'provider_account_id' => 'spotify-secret-id',
            'label' => 'Domowe Spotify',
            'scopes' => ['private-scope-canary'],
            'refresh_token' => 'spotify-refresh-secret',
        ]);
        StreamingAccount::factory()->for($user)->youtube()->reconnectRequired()->create([
            'provider_account_id' => 'youtube-secret-id',
            'label' => 'Kanał koncertowy',
            'scopes' => ['youtube-scope-canary'],
        ]);

        $response = $this->actingAs($user)->get(route('integrations.index'));

        $response->assertOk()
            ->assertSee('Domowe Spotify')
            ->assertSee('Kanał koncertowy')
            ->assertSee('Połączone')
            ->assertSee('Wymaga ponownego połączenia')
            ->assertSee('Sprawdź połączenie')
            ->assertSee('Połącz ponownie YouTube')
            ->assertSee('Remove Access')
            ->assertSee(route('integrations.accounts.verify', $spotify), false)
            ->assertSee('method="POST"', false)
            ->assertDontSee('spotify-secret-id')
            ->assertDontSee('youtube-secret-id')
            ->assertDontSee('private-scope-canary')
            ->assertDontSee('youtube-scope-canary')
            ->assertDontSee('spotify-refresh-secret');

        $this->assertSame(1, substr_count($response->getContent(), 'Sprawdź połączenie'));
    }

    public function test_default_synchronization_seam_is_an_explicit_noop(): void
    {
        $seam = $this->app->make(DisableDependentStreamingSynchronizations::class);
        $account = StreamingAccount::factory()->spotify()->create();

        $seam->handle($account);

        $this->assertDatabaseHas('streaming_accounts', ['id' => $account->id]);
    }

    public function test_youtube_unlink_disables_dependents_deletes_before_revoke_and_confirms_revocation(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->youtube()->create([
            'refresh_token' => 'youtube-revoke-canary',
        ]);
        $seam = new RecordingDisableSynchronizations;
        $gateway = new ManagementFakeGateway($account->id);
        $this->app->instance(DisableDependentStreamingSynchronizations::class, $seam);
        $this->app->instance('streaming-oauth.youtube', $gateway);
        Log::spy();

        $response = $this->actingAs($user)
            ->withSession(['session-canary' => 'preserved'])
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes']);

        $response->assertRedirect(route('integrations.index'))
            ->assertSessionHas('status', 'Konto YouTube zostało odłączone lokalnie, a dostęp u Google został cofnięty.')
            ->assertSessionHas('session-canary', 'preserved');
        $this->assertSame([$account->id], $seam->accountIds);
        $this->assertSame(['youtube-revoke-canary'], $gateway->revokedTokens);
        $this->assertTrue($gateway->accountWasDeletedBeforeRevoke);
        $this->assertDatabaseMissing('streaming_accounts', ['id' => $account->id]);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    public function test_provider_failure_never_restores_the_locally_deleted_youtube_account(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->youtube()->create();
        $gateway = new ManagementFakeGateway($account->id, StreamingOAuthFailure::TemporarilyUnavailable);
        $this->app->instance('streaming-oauth.youtube', $gateway);

        $response = $this->actingAs($user)
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes']);

        $response->assertRedirect(route('integrations.index'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'odłączone lokalnie')
                && str_contains($message, 'Nie udało się potwierdzić'));
        $this->assertDatabaseMissing('streaming_accounts', ['id' => $account->id]);
    }

    public function test_spotify_unlink_is_local_only_and_instructs_manual_remove_access(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create();
        $gateway = new ManagementFakeGateway($account->id);
        $this->app->instance('streaming-oauth.spotify', $gateway);

        $response = $this->actingAs($user)
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes']);

        $response->assertRedirect(route('integrations.index'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'odłączone lokalnie')
                && str_contains($message, 'Remove Access'));
        $this->assertSame([], $gateway->revokedTokens);
        $this->assertDatabaseMissing('streaming_accounts', ['id' => $account->id]);
    }

    public function test_unlink_preserves_google_login_identity_and_authenticated_session(): void
    {
        $user = User::factory()->create();
        $identity = new AuthIdentity([
            'provider' => 'google',
            'provider_user_id' => 'google-login-canary',
        ]);
        $identity->user()->associate($user);
        $identity->save();
        $account = StreamingAccount::factory()->for($user)->youtube()->create();
        $this->app->instance('streaming-oauth.youtube', new ManagementFakeGateway($account->id));

        $this->actingAs($user)
            ->withSession(['session-canary' => 'preserved'])
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes'])
            ->assertSessionHas('session-canary', 'preserved');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_identities', ['id' => $identity->id]);
    }
}

final class RecordingDisableSynchronizations implements DisableDependentStreamingSynchronizations
{
    /** @var list<int> */
    public array $accountIds = [];

    public function handle(StreamingAccount $account): void
    {
        if (! $account->exists || ! StreamingAccount::query()->whereKey($account->id)->exists()) {
            throw new \RuntimeException('The account must still exist while dependents are disabled.');
        }

        $this->accountIds[] = $account->id;
    }
}

final class ManagementFakeGateway implements StreamingOAuthGateway
{
    /** @var list<string> */
    public array $revokedTokens = [];

    public bool $accountWasDeletedBeforeRevoke = false;

    public function __construct(
        private readonly int $accountId,
        private readonly ?StreamingOAuthFailure $revokeFailure = null,
    ) {}

    public function authorizationUrl(string $state): string
    {
        throw new \LogicException('Not used by management tests.');
    }

    public function exchange(string $code): StreamingGrant|StreamingOAuthFailure
    {
        throw new \LogicException('Not used by management tests.');
    }

    public function refresh(string $refreshToken): StreamingGrant|StreamingOAuthFailure
    {
        throw new \LogicException('Not used by management tests.');
    }

    public function identity(string $accessToken): StreamingIdentity|StreamingOAuthFailure
    {
        throw new \LogicException('Not used by management tests.');
    }

    public function revoke(string $token): ?StreamingOAuthFailure
    {
        $this->revokedTokens[] = $token;
        $this->accountWasDeletedBeforeRevoke = ! StreamingAccount::query()->whereKey($this->accountId)->exists();

        return $this->revokeFailure;
    }
}
