<?php

namespace Tests\Feature;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\PlaylistRole;
use App\Enums\StreamingProvider;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExportLink;
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

    public function test_export_target_is_nested_under_its_source_with_owner_status_and_local_link(): void
    {
        $owner = User::factory()->create();
        $source = Playlist::factory()->for($owner)->create();
        $providerId = '0123456789ABCDEFGHIJKL';
        $target = Playlist::factory()->for($owner)->create([
            'role' => PlaylistRole::ExportTarget,
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => $providerId,
            'source_account_id' => 'spotify-account',
            'canonical_source_url' => 'javascript:alert(1)',
        ]);
        $link = PlaylistExportLink::factory()->create([
            'user_id' => $owner->id,
            'source_playlist_id' => $source->id,
            'target_playlist_id' => $target->id,
            'provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => 'spotify-account',
        ]);
        $oldReview = ExportReview::factory()->for($source)->create([
            'user_id' => $owner->id,
            'target_account_id' => 'spotify-account',
            'destination_type' => ExportDestinationType::Linked,
        ]);
        $oldOperation = ExportOperation::factory()->create([
            'export_review_id' => $oldReview->id,
            'user_id' => $owner->id,
            'source_playlist_id' => $source->id,
            'playlist_export_link_id' => $link->id,
            'target_account_id' => 'spotify-account',
            'destination_type' => ExportDestinationType::Linked,
            'status' => ExportOperationStatus::Failed,
            'active_key' => null,
            'completed_at' => now()->subMinute(),
        ]);
        $review = ExportReview::factory()->for($source)->create([
            'user_id' => $owner->id,
            'target_account_id' => 'spotify-account',
            'destination_type' => ExportDestinationType::Linked,
        ]);
        $operation = ExportOperation::factory()->create([
            'export_review_id' => $review->id,
            'user_id' => $owner->id,
            'source_playlist_id' => $source->id,
            'playlist_export_link_id' => $link->id,
            'target_account_id' => 'spotify-account',
            'destination_type' => ExportDestinationType::Linked,
            'status' => ExportOperationStatus::Transferred,
            'active_key' => null,
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Powiązane kopie')
            ->assertSee('Właściciel: Twoje połączone konto')
            ->assertSee('Przeniesiona — na Twoim koncie')
            ->assertSee('https://open.spotify.com/playlist/'.$providerId, false)
            ->assertSee(route('export-operations.show', [$source, $operation->operation_id]), false)
            ->assertDontSee(route('export-operations.show', [$source, $oldOperation->operation_id]), false)
            ->assertDontSee('javascript:alert', false);

        $this->assertSame(1, substr_count($this->actingAs($owner)->get(route('bank.index'))->getContent(), 'Przeglądaj i edytuj'));
    }

    public function test_active_export_links_to_status_and_suppresses_a_new_review_for_that_destination(): void
    {
        config(['services.platform_access.spotify.technical.account_id' => 'managed-spotify']);
        $owner = User::factory()->create();
        $source = Playlist::factory()->for($owner)->create();
        $oldReview = ExportReview::factory()->for($source)->create([
            'user_id' => $owner->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-spotify',
        ]);
        $oldOperation = ExportOperation::factory()->create([
            'export_review_id' => $oldReview->id,
            'user_id' => $owner->id,
            'source_playlist_id' => $source->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-spotify',
            'status' => ExportOperationStatus::Transferred,
            'active_key' => null,
            'started_at' => now()->subMinute(),
            'completed_at' => now()->subSeconds(30),
        ]);
        $review = ExportReview::factory()->for($source)->create([
            'user_id' => $owner->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-spotify',
        ]);
        $operation = ExportOperation::factory()->create([
            'export_review_id' => $review->id,
            'user_id' => $owner->id,
            'source_playlist_id' => $source->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => ExportDestinationType::Managed,
            'target_account_id' => 'managed-spotify',
            'status' => ExportOperationStatus::Processing,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('bank.index'));

        $response
            ->assertOk()
            ->assertSee('Otwórz aktywny eksport')
            ->assertSee(route('export-operations.show', [$source, $operation->operation_id]), false)
            ->assertDontSee(route('export-operations.show', [$source, $oldOperation->operation_id]), false);

        $this->assertSame(1, substr_count($response->getContent(), 'Otwórz aktywny eksport'));
    }

    public function test_bank_paginates_source_playlists_twenty_per_page(): void
    {
        $owner = User::factory()->create();
        Playlist::factory()->for($owner)->create([
            'name' => 'Oldest playlist on page two',
            'source_playlist_id' => 'oldest-page-two',
            'imported_at' => now()->subDay(),
        ]);
        foreach (range(1, 20) as $index) {
            Playlist::factory()->for($owner)->create([
                'name' => "Recent playlist {$index}",
                'source_playlist_id' => "recent-page-one-{$index}",
                'imported_at' => now()->addSeconds($index),
            ]);
        }

        $this->actingAs($owner)
            ->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Recent playlist 20')
            ->assertDontSee('Oldest playlist on page two');

        $this->actingAs($owner)
            ->get(route('bank.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Oldest playlist on page two');
    }
}
