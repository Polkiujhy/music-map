<?php

namespace Tests\Feature\PlaylistSync;

use App\Enums\PlaylistSyncStatus;
use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaylistSynchronizationActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_prepares_and_confirms_an_activation_without_enabling_auto_or_admitting_a_write(): void
    {
        [$user, $playlist] = $this->spotifyPlaylist();
        $this->app->instance(WithStreamingAccess::class, new SyncAccessFake(StreamingProvider::Spotify, 'owner-canary'));
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->spotifyMetadata())
            ->push($this->spotifyItems())
            ->push($this->spotifyMetadata())
            ->push($this->spotifyItems());

        $preview = $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertOk()
            ->assertJsonPath('direction', 'no-op')
            ->json();

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.confirm', $playlist), [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertAccepted()
            ->assertJsonPath('automatic_enabled', false);

        $this->assertDatabaseHas('playlist_synchronizations', [
            'playlist_id' => $playlist->id,
            'status' => PlaylistSyncStatus::Enabled->value,
            'automatic_enabled' => false,
        ]);
        $run = PlaylistSyncRun::query()->where('trigger', 'activation')->firstOrFail();
        $this->assertSame('pending', $run->state);
        $this->assertDatabaseMissing('youtube_write_admissions', [
            'operation_type' => 'source-sync',
            'operation_id' => $run->operation_id,
        ]);
        Http::assertSentCount(4);

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.confirm', $playlist), [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview_token');
        Http::assertSentCount(4);
    }

    public function test_provider_backed_prepare_is_rate_limited_per_user_and_provider(): void
    {
        [$user, $playlist] = $this->spotifyPlaylist();
        $this->app->instance(WithStreamingAccess::class, new SyncAccessFake(StreamingProvider::Spotify, 'owner-canary'));
        Http::preventStrayRequests();
        $responses = Http::fakeSequence();
        foreach (range(1, 5) as $attempt) {
            $responses->push($this->spotifyMetadata())->push($this->spotifyItems());
        }

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)
                ->postJson(route('playlist-synchronizations.prepare', $playlist))
                ->assertOk();
        }

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertStatus(429);
        Http::assertSentCount(10);
    }

    public function test_changed_bank_invalidates_the_preview_without_creating_an_activation_run(): void
    {
        [$user, $playlist] = $this->spotifyPlaylist();
        $this->app->instance(WithStreamingAccess::class, new SyncAccessFake(StreamingProvider::Spotify, 'owner-canary'));
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->spotifyMetadata())->push($this->spotifyItems());

        $token = $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertOk()
            ->json('preview_token');

        $playlist->update(['name' => 'Changed after preview']);

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.confirm', $playlist), ['preview_token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview_token');

        $this->assertDatabaseCount('playlist_sync_runs', 0);
        Http::assertSentCount(2);
    }

    public function test_collaborator_and_foreign_bank_owner_are_rejected(): void
    {
        [$user, $playlist] = $this->spotifyPlaylist();
        $this->app->instance(WithStreamingAccess::class, new SyncAccessFake(StreamingProvider::Spotify, 'owner-canary'));
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->spotifyMetadata(owner: 'different-owner'))
            ->push($this->spotifyItems());

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('playlist');

        $other = User::factory()->create();
        $this->actingAs($other)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertNotFound();
    }

    public function test_historical_spotify_grant_is_marked_for_reconnect_before_provider_read(): void
    {
        [$user, $playlist, $account] = $this->spotifyPlaylist([
            'playlist-modify-private',
            'playlist-read-collaborative',
            'playlist-read-private',
            'user-read-private',
        ]);
        $this->app->instance(WithStreamingAccess::class, new SyncAccessFake(StreamingProvider::Spotify, 'owner-canary'));
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');

        $this->assertNull($account->refresh()->refresh_token);
        Http::assertNothingSent();
    }

    /** @return array{User, Playlist, StreamingAccount} */
    private function spotifyPlaylist(?array $scopes = null): array
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create([
            'provider_account_id' => 'owner-canary',
            'scopes' => $scopes ?? StreamingProvider::Spotify->requiredScopes(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'playlist-canary',
            'source_account_id' => 'historical-value-is-not-trusted',
            'streaming_account_id' => $account->id,
            'provider_revision' => 'revision-canary',
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'catalog_id' => 'track-canary',
            'catalog_uri' => 'spotify:track:track-canary',
        ]);

        return [$user, $playlist, $account];
    }

    private function spotifyMetadata(string $owner = 'owner-canary'): array
    {
        return [
            'id' => 'playlist-canary',
            'owner' => ['id' => $owner],
            'snapshot_id' => 'revision-canary',
            'tracks' => ['total' => 1],
        ];
    }

    private function spotifyItems(): array
    {
        return ['items' => [[
            'item' => [
                'type' => 'track',
                'id' => 'track-canary',
                'uri' => 'spotify:track:track-canary',
                'name' => 'Track',
                'artists' => [['name' => 'Artist']],
            ],
        ]], 'next' => null];
    }
}

final class SyncAccessFake implements WithStreamingAccess
{
    public function __construct(
        private readonly StreamingProvider $provider,
        private readonly string $providerAccountId,
    ) {}

    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext($this->provider, $this->providerAccountId, 'access-canary'));

        return StreamingAccessResult::success();
    }
}
