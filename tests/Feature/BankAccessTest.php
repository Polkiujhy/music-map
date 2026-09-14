<?php

namespace Tests\Feature;

use App\Models\Playlist;
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
            ->assertSee('Importuj playlistę')
            ->assertDontSee($otherUser->name)
            ->assertDontSee($otherUser->email);
    }

    public function test_bank_has_logout_and_explicit_consent_import_controls(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('action="'.route('playlists.import').'"', false)
            ->assertSee('name="policy_consent"', false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.privacy'), false)
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

    public function test_each_owned_playlist_card_has_exactly_one_editor_entry(): void
    {
        $owner = User::factory()->create();
        $ownedPlaylists = collect([
            Playlist::factory()->for($owner)->create(['source_playlist_id' => 'owned-playlist-1']),
            Playlist::factory()->for($owner)->create(['source_playlist_id' => 'owned-playlist-2']),
        ]);
        $foreignPlaylist = Playlist::factory()->create();

        $response = $this->actingAs($owner)
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Przeglądaj i edytuj');

        foreach ($ownedPlaylists as $playlist) {
            $response->assertSee(route('bank.playlists.edit', $playlist), false);
        }

        $response
            ->assertSeeInOrder(['Przeglądaj i edytuj', 'Przeglądaj i edytuj'])
            ->assertDontSee(route('bank.playlists.edit', $foreignPlaylist), false);

        $this->assertSame(2, substr_count($response->getContent(), 'Przeglądaj i edytuj'));
    }
}
