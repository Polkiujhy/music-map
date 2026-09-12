<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_is_public_and_uses_the_named_home_route(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Twoja muzyka.')
            ->assertSee('Poza platformami.');
    }

    public function test_guests_see_registration_and_login_calls_to_action(): void
    {
        $this->get(route('home'))
            ->assertSee(route('register'), false)
            ->assertSee(route('login'), false)
            ->assertSee('Utwórz prywatny bank')
            ->assertDontSee('Otwórz mój bank');
    }

    public function test_authenticated_users_see_the_bank_call_to_action(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(route('bank.index'), false)
            ->assertSee('Otwórz mój bank')
            ->assertDontSee('Utwórz prywatny bank');
    }
}
