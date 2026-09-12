<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Utwórz konto');
    }

    public function test_new_users_can_register_with_a_normalized_email_address(): void
    {
        Event::fake([Registered::class]);

        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => '  ADA@Example.COM ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/bank');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'email_verified_at' => null,
        ]);
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_rejects_an_existing_email_regardless_of_case_or_whitespace(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => ' ADA@EXAMPLE.COM ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 1);
    }
}
