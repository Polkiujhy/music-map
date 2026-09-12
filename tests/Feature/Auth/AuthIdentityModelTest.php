<?php

namespace Tests\Feature\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthIdentityModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_passwordless_users_are_persisted_and_must_verify_their_email(): void
    {
        $user = User::factory()->passwordless()->unverified()->create();

        $this->assertNull($user->password);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'password' => null,
        ]);
    }

    public function test_identity_belongs_to_its_user_and_is_deleted_with_that_user(): void
    {
        $identity = AuthIdentity::factory()->create();
        $user = $identity->user;

        $this->assertTrue($user->authIdentities->contains($identity));

        $user->delete();

        $this->assertDatabaseMissing('auth_identities', ['id' => $identity->id]);
    }

    public function test_provider_subject_must_be_unique(): void
    {
        AuthIdentity::factory()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject',
        ]);

        $this->expectException(QueryException::class);

        AuthIdentity::factory()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject',
        ]);
    }

    public function test_user_can_only_have_one_identity_for_each_provider(): void
    {
        $user = User::factory()->create();

        AuthIdentity::factory()->for($user)->create(['provider' => 'google']);

        $this->expectException(QueryException::class);

        AuthIdentity::factory()->for($user)->create(['provider' => 'google']);
    }

    public function test_auth_identity_does_not_store_provider_tokens(): void
    {
        $identity = AuthIdentity::factory()->create();

        $this->assertSame(['provider', 'provider_user_id'], $identity->getFillable());
        $this->assertArrayNotHasKey('token', $identity->getAttributes());
        $this->assertArrayNotHasKey('refresh_token', $identity->getAttributes());
    }
}
