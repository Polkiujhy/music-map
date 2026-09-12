<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_without_bank_content(): void
    {
        $this->get(route('bank.index'))
            ->assertRedirect(route('login'))
            ->assertDontSee('Bank czeka na pierwszą playlistę');
    }

    public function test_unverified_users_are_redirected_to_email_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('bank.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_users_see_only_their_account_context(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Właścicielka',
            'email' => 'ada@example.com',
        ]);
        $otherUser = User::factory()->create([
            'name' => 'Inny Użytkownik',
            'email' => 'other@example.com',
        ]);

        $this->actingAs($user)
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Ada Właścicielka')
            ->assertSee('ada@example.com')
            ->assertSee('Widoczne tylko dla Ciebie')
            ->assertSee('Import playlist uruchomimy w kolejnym etapie')
            ->assertDontSee($otherUser->name)
            ->assertDontSee($otherUser->email);
    }

    public function test_bank_has_only_the_logout_form_and_no_import_control(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false)
            ->assertDontSee('type="file"', false)
            ->assertDontSee('disabled', false);
    }

    public function test_user_can_log_out_from_the_bank_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
