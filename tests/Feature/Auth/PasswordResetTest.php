<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_link_screen_can_be_rendered(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Odzyskaj dostęp');
    }

    public function test_password_reset_link_request_is_neutral_and_normalizes_the_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => ' ADA@EXAMPLE.COM ',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);

        $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'nobody@example.com',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHas('status');
    }

    public function test_password_can_be_reset_using_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => ' ADA@EXAMPLE.COM ',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_invalid_reset_token_does_not_change_the_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->from(route('password.reset', ['token' => 'invalid']))->post(route('password.update'), [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('password.reset', ['token' => 'invalid']))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
