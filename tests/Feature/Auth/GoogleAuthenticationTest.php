<?php

namespace Tests\Feature\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Auth\ResolveGoogleIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_is_stateful(): void
    {
        config()->set('services.google', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'https://music-map.test/auth/google/callback',
        ]);

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirectContains('accounts.google.com/o/oauth2/auth');
        $this->assertIsString(session('state'));
        $this->assertNotSame('', session('state'));
    }

    public function test_login_and_registration_show_the_same_google_entry(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('auth.google.redirect'))
            ->assertSee('Kontynuuj przez Google');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('auth.google.redirect'))
            ->assertSee('Kontynuuj przez Google');
    }

    public function test_verified_google_user_creates_a_verified_passwordless_account(): void
    {
        $this->fakeGoogleUser([
            'id' => 'google-subject',
            'name' => 'Ada Lovelace',
            'email' => ' ADA@Example.COM ',
            'email_verified' => true,
        ]);

        $this->googleCallback()->assertRedirect(route('bank.index'));

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject',
        ]);
    }

    public function test_verified_email_compatibility_field_is_accepted(): void
    {
        $this->fakeGoogleUser([
            'id' => 'google-subject',
            'email' => 'ada@example.com',
            'verified_email' => true,
        ]);

        $this->googleCallback()->assertRedirect(route('bank.index'));

        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_google_links_and_verifies_an_existing_email_account(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
        $this->fakeGoogleUser([
            'id' => 'google-subject',
            'email' => ' ADA@EXAMPLE.COM ',
            'email_verified' => true,
        ]);

        $this->googleCallback()->assertRedirect(route('bank.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_repeated_callback_keeps_one_user_and_identity(): void
    {
        $attributes = [
            'id' => 'google-subject',
            'email' => 'ada@example.com',
            'email_verified' => true,
        ];

        $this->fakeGoogleUser($attributes);
        $this->googleCallback()->assertRedirect(route('bank.index'));
        $this->post(route('logout'));

        $this->fakeGoogleUser($attributes);
        $this->googleCallback()->assertRedirect(route('bank.index'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_subject_conflict_is_refused_without_changing_data(): void
    {
        $identity = AuthIdentity::factory()->create([
            'provider_user_id' => 'google-subject',
        ]);
        User::factory()->create(['email' => 'other@example.com']);
        $this->fakeGoogleUser([
            'id' => 'google-subject',
            'email' => 'other@example.com',
            'email_verified' => true,
        ]);

        $this->googleCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('auth_identities', 1);
        $this->assertSame($identity->user_id, AuthIdentity::query()->sole()->user_id);
    }

    public function test_missing_or_mismatched_state_stops_before_provider_and_resolver(): void
    {
        $resolver = Mockery::mock(ResolveGoogleIdentity::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(ResolveGoogleIdentity::class, $resolver);

        Socialite::fake('google', function (): never {
            throw new RuntimeException('Provider should not be called.');
        });

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->withSession(['state' => 'expected'])
            ->get(route('auth.google.callback', ['state' => 'different']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
    }

    public function test_cancelled_authorization_stops_before_provider_and_resolver(): void
    {
        $resolver = Mockery::mock(ResolveGoogleIdentity::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(ResolveGoogleIdentity::class, $resolver);

        Socialite::fake('google', function (): never {
            throw new RuntimeException('Provider should not be called.');
        });

        $this->withSession(['state' => 'valid-state'])
            ->get(route('auth.google.callback', [
                'state' => 'valid-state',
                'error' => 'access_denied',
                'code' => 'must-not-be-logged',
            ]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
    }

    public function test_google_oauth_is_throttled_before_provider_and_resolver_work(): void
    {
        config()->set('services.google', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect' => 'https://music-map.test/auth/google/callback',
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get(route('auth.google.redirect'))->assertRedirect();
        }

        $resolver = Mockery::mock(ResolveGoogleIdentity::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(ResolveGoogleIdentity::class, $resolver);

        Socialite::fake('google', function (): never {
            throw new RuntimeException('Provider should not be called.');
        });

        $this->withSession(['state' => 'valid-state'])
            ->get(route('auth.google.callback', ['state' => 'valid-state']))
            ->assertTooManyRequests();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
    }

    public function test_provider_failure_is_safe_and_does_not_write_data(): void
    {
        Log::spy();

        Socialite::fake('google', function (): never {
            throw new RuntimeException('Provider failed with sensitive details.');
        });

        $this->googleCallback()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('google');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Google OAuth callback failed.'
                    && array_keys($context) === ['exception_class', 'correlation_id']
                    && $context['exception_class'] === RuntimeException::class
                    && Str::isUuid($context['correlation_id'])
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sensitive details');
            });
    }

    public function test_unverified_missing_or_non_boolean_verification_is_refused(): void
    {
        foreach ([false, null, 'true', 1] as $verification) {
            $attributes = [
                'id' => 'google-subject',
                'email' => 'ada@example.com',
            ];

            if ($verification !== null) {
                $attributes['email_verified'] = $verification;
            }

            $this->fakeGoogleUser($attributes);
            $this->googleCallback()
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('google');
        }

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
    }

    public function test_missing_subject_name_or_email_is_refused(): void
    {
        foreach (['id', 'name', 'email'] as $missingAttribute) {
            $attributes = [
                'id' => 'google-subject',
                'name' => 'Ada Lovelace',
                'email' => 'ada@example.com',
                'email_verified' => true,
            ];
            $attributes[$missingAttribute] = '';

            $this->fakeGoogleUser($attributes);
            $this->googleCallback()
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('google');
        }

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('auth_identities', 0);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function fakeGoogleUser(array $attributes): void
    {
        Socialite::fake('google', SocialiteUser::fake($attributes));
    }

    private function googleCallback()
    {
        return $this->withSession(['state' => 'valid-state'])
            ->get(route('auth.google.callback', ['state' => 'valid-state']));
    }
}
