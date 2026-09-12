<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_regenerates_the_session_and_only_sets_a_recaller_when_requested(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $oldSessionId = session()->getId();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/bank');
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertEmpty(array_filter(
            $response->headers->getCookies(),
            fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'),
        ));

        $this->post(route('logout'));

        $remembered = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertNotEmpty(array_filter(
            $remembered->headers->getCookies(),
            fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'),
        ));
    }

    public function test_logout_invalidates_the_session_and_regenerates_the_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $oldToken = session()->token();

        $this->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
        $this->assertNotSame($oldToken, session()->token());
    }
}
