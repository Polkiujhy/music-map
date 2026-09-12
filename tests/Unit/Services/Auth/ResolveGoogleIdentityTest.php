<?php

namespace Tests\Unit\Services\Auth;

use App\Exceptions\Auth\IdentityConflictException;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Auth\ResolveGoogleIdentity;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class ResolveGoogleIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_verified_passwordless_user_and_identity(): void
    {
        $user = (new ResolveGoogleIdentity)->resolve(
            ' google-subject ',
            ' Ada Lovelace ',
            ' ADA@Example.COM ',
        );

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull($user->password);
        $this->assertDatabaseHas('auth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject',
        ]);
    }

    public function test_it_links_and_verifies_an_existing_email_account_without_renaming_it(): void
    {
        $user = User::factory()->unverified()->create([
            'name' => 'Existing Name',
            'email' => 'ada@example.com',
        ]);

        $resolved = (new ResolveGoogleIdentity)->resolve(
            'google-subject',
            'Google Name',
            ' ADA@EXAMPLE.COM ',
        );

        $this->assertTrue($resolved->is($user));
        $this->assertSame('Existing Name', $resolved->name);
        $this->assertTrue($resolved->hasVerifiedEmail());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_repeated_resolution_is_idempotent(): void
    {
        $resolver = new ResolveGoogleIdentity;

        $first = $resolver->resolve('google-subject', 'Ada', 'ada@example.com');
        $second = $resolver->resolve('google-subject', 'Changed Google Name', 'ada@example.com');

        $this->assertTrue($second->is($first));
        $this->assertSame('Ada', $second->name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_existing_subject_can_log_in_after_google_changes_to_an_unused_email(): void
    {
        $identity = AuthIdentity::factory()->create([
            'provider_user_id' => 'google-subject',
        ]);

        $resolved = (new ResolveGoogleIdentity)->resolve(
            'google-subject',
            'Changed Name',
            'new-address@example.com',
        );

        $this->assertTrue($resolved->is($identity->user));
        $this->assertSame($identity->user->email, $resolved->email);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_existing_subject_is_refused_when_the_new_email_belongs_to_another_user(): void
    {
        $identity = AuthIdentity::factory()->create([
            'provider_user_id' => 'google-subject',
        ]);
        User::factory()->create(['email' => 'other@example.com']);

        try {
            (new ResolveGoogleIdentity)->resolve(
                'google-subject',
                'Changed Name',
                'other@example.com',
            );
            $this->fail('An identity conflict should have been raised.');
        } catch (IdentityConflictException) {
            $this->assertDatabaseCount('users', 2);
            $this->assertDatabaseCount('auth_identities', 1);
            $this->assertSame($identity->user_id, AuthIdentity::query()->sole()->user_id);
        }
    }

    public function test_a_second_google_subject_for_one_user_is_refused_atomically(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
        AuthIdentity::factory()->for($user)->create([
            'provider_user_id' => 'first-subject',
        ]);

        try {
            (new ResolveGoogleIdentity)->resolve(
                'second-subject',
                'Ada',
                'ada@example.com',
            );
            $this->fail('An identity conflict should have been raised.');
        } catch (IdentityConflictException) {
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('auth_identities', 1);
        }
    }

    public function test_provider_subject_unique_collision_is_re_read_as_one_account(): void
    {
        $resolver = new SimulatedCollisionResolver(function (): void {
            $user = User::factory()->create(['email' => 'ada@example.com']);
            AuthIdentity::factory()->for($user)->create([
                'provider_user_id' => 'google-subject',
            ]);
        });

        $resolved = $resolver->resolve('google-subject', 'Ada', 'ada@example.com');

        $this->assertSame('ada@example.com', $resolved->email);
        $this->assertSame(2, $resolver->attempts);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function test_user_provider_unique_collision_is_re_read_and_refused_atomically(): void
    {
        $resolver = new SimulatedCollisionResolver(function (): void {
            $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
            AuthIdentity::factory()->for($user)->create([
                'provider_user_id' => 'winning-subject',
            ]);
        });

        try {
            $resolver->resolve('losing-subject', 'Ada', 'ada@example.com');
            $this->fail('An identity conflict should have been raised.');
        } catch (IdentityConflictException) {
            $this->assertSame(2, $resolver->attempts);
            $this->assertFalse(User::query()->sole()->hasVerifiedEmail());
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('auth_identities', 1);
        }
    }
}

class SimulatedCollisionResolver extends ResolveGoogleIdentity
{
    public int $attempts = 0;

    public function __construct(private readonly Closure $beforeCollision) {}

    protected function resolveAttempt(string $subject, string $name, string $email): User
    {
        $this->attempts++;

        if ($this->attempts === 1) {
            ($this->beforeCollision)();

            $previous = new PDOException('simulated unique collision');

            throw new UniqueConstraintViolationException(
                'sqlite',
                'insert into auth_identities',
                [],
                $previous,
            );
        }

        return parent::resolveAttempt($subject, $name, $email);
    }
}
