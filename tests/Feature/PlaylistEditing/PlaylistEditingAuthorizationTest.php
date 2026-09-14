<?php

namespace Tests\Feature\PlaylistEditing;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaylistEditingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_editor_route_is_named_and_protected_by_authentication_and_verification(): void
    {
        $playlist = Playlist::factory()->create();

        $this->assertSame(
            '/bank/playlists/'.$playlist->id.'/edit',
            route('bank.playlists.edit', $playlist, false),
        );

        $this->get(route('bank.playlists.edit', $playlist))
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('bank.playlists.edit', $playlist))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_an_owner_can_open_the_read_only_source_wrapper(): void
    {
        $playlist = Playlist::factory()->create([
            'name' => 'Własna playlista',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-owned',
        ]);

        $this->actingAs($playlist->user)
            ->get(route('bank.playlists.edit', $playlist))
            ->assertOk()
            ->assertSee('Własna playlista')
            ->assertSee('Źródło: YouTube')
            ->assertSee($playlist->canonical_source_url, false)
            ->assertSeeLivewire('playlist-editor');
    }

    public function test_foreign_deleted_and_malformed_playlist_ids_are_not_disclosed(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignPlaylist = Playlist::factory()->for($otherUser)->create();
        $deletedPlaylist = Playlist::factory()->for($owner)->create();
        $deletedId = $deletedPlaylist->id;
        $deletedPlaylist->delete();

        $this->actingAs($owner)
            ->get(route('bank.playlists.edit', $foreignPlaylist))
            ->assertNotFound();

        $this->get(route('bank.playlists.edit', $deletedId))
            ->assertNotFound();

        $this->get(route('bank.playlists.edit', 'not-an-id'))
            ->assertNotFound();
    }
}
