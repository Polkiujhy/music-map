<?php

namespace Tests\Feature\PlaylistEditing;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EditedPlaylistReimportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.playlist_import.youtube.api_key', 'api-key-canary');
    }

    public function test_general_import_refuses_an_edited_source_before_provider_work(): void
    {
        Http::fake();
        $playlist = $this->editedPlaylist();
        $before = $playlist->fresh()->load('items')->toArray();

        $this->actingAs($playlist->user)
            ->post(route('playlists.import'), [
                'playlist_url' => $playlist->canonical_source_url,
                'policy_consent' => '1',
            ])
            ->assertRedirect(route('bank.index'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'Otwórz jej właścicielski edytor i użyj świadomego reimportu',
            ));

        Http::assertNothingSent();
        $this->assertSame($before, $playlist->fresh()->load('items')->toArray());
    }

    public function test_dedicated_reimport_is_owner_scoped_and_requires_confirmation_before_provider_work(): void
    {
        Http::fake();
        $playlist = $this->editedPlaylist();

        $this->post(route('bank.playlists.reimport', $playlist), ['confirm_reimport' => 'yes'])
            ->assertRedirect(route('login'));

        $this->actingAs(User::factory()->unverified()->create())
            ->post(route('bank.playlists.reimport', $playlist), ['confirm_reimport' => 'yes'])
            ->assertRedirect(route('verification.notice'));

        $this->actingAs(User::factory()->create())
            ->post(route('bank.playlists.reimport', $playlist), ['confirm_reimport' => 'yes'])
            ->assertNotFound();

        $this->actingAs($playlist->user)
            ->from(route('bank.playlists.edit', $playlist))
            ->post(route('bank.playlists.reimport', $playlist))
            ->assertRedirect(route('bank.playlists.edit', $playlist))
            ->assertSessionHasErrors('confirm_reimport');

        Http::assertNothingSent();
    }

    public function test_confirmed_reimport_uses_the_saved_url_replaces_the_snapshot_and_clears_the_marker(): void
    {
        $playlist = $this->editedPlaylist();
        Http::fake(function (Request $request) use ($playlist) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame(
                $playlist->source_playlist_id,
                $query['list'] ?? $query['id'] ?? $query['playlistId'] ?? null,
            );

            return str_contains($request->url(), '/playlistItems?')
                ? Http::response($this->items(['source-first', 'source-second']))
                : Http::response($this->metadata('Platform replacement', 2));
        });

        $this->actingAs($playlist->user)
            ->post(route('bank.playlists.reimport', $playlist), [
                'confirm_reimport' => 'yes',
                'playlist_url' => 'https://www.youtube.com/playlist?list=PL-attacker',
            ])
            ->assertRedirect(route('bank.playlists.edit', $playlist))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'w pełni zaimportowana ponownie'));

        $playlist->refresh()->load('items');
        $this->assertSame('Platform replacement', $playlist->name);
        $this->assertSame(['source-first', 'source-second'], $playlist->items->pluck('catalog_id')->all());
        $this->assertNull($playlist->bank_content_edited_at);
        Http::assertSentCount(2);
    }

    public function test_editor_explains_the_difference_and_warns_about_replacement(): void
    {
        $playlist = $this->editedPlaylist();

        $this->actingAs($playlist->user)
            ->get(route('bank.playlists.edit', $playlist))
            ->assertOk()
            ->assertSee('Automatyczne odświeżenie zachowuje lokalną kolejność i usunięcia')
            ->assertSee('Pełny reimport z platformy')
            ->assertSee('bezpowrotnie zastąpi lokalną kolejność oraz wszystkie lokalne usunięcia')
            ->assertSee('Potwierdź pełny reimport');
    }

    private function editedPlaylist(): Playlist
    {
        $playlist = Playlist::factory()->create([
            'source_playlist_id' => 'PL-edited-reimport',
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-edited-reimport',
            'bank_content_edited_at' => now()->subHour(),
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'occurrence_id' => 'local-occurrence',
            'catalog_id' => 'local-video',
        ]);

        return $playlist->load('user', 'items');
    }

    private function metadata(string $name, int $count): array
    {
        return ['items' => [[
            'id' => 'PL-edited-reimport',
            'etag' => 'replacement-revision',
            'snippet' => ['title' => $name, 'description' => 'Replacement description', 'channelId' => 'channel-canary'],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    /** @param list<string> $videoIds */
    private function items(array $videoIds): array
    {
        return [
            'items' => array_map(static fn (string $videoId, int $position): array => [
                'id' => "replacement-occurrence-{$position}",
                'snippet' => [
                    'position' => $position,
                    'title' => "Replacement item {$position}",
                    'videoOwnerChannelTitle' => 'Creator',
                    'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
                ],
            ], $videoIds, array_keys($videoIds)),
            'pageInfo' => ['totalResults' => count($videoIds)],
        ];
    }
}
