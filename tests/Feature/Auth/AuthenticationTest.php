<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Zaloguj się');
    }

    public function test_users_can_authenticate_with_a_normalized_email_address(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post(route('login.store'), [
            'email' => ' ADA@EXAMPLE.COM ',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/bank');
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'ada@example.com',
            'password' => 'incorrect-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        foreach (range(1, 4) as $attempt) {
            $this->from(route('login'))->post(route('login.store'), [
                'email' => 'ada@example.com',
                'password' => 'incorrect-password',
            ])->assertRedirect(route('login'));
        }

        $this->from(route('login'))->post(route('login.store'), [
            'email' => 'ada@example.com',
            'password' => 'incorrect-password',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }
}
