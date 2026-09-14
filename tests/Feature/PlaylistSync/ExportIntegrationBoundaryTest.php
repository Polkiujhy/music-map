<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\PlaylistSync\ApplySourcePlaylistToBank;
use App\Actions\PlaylistSync\DispatchPlaylistSynchronization;
use App\Actions\PlaylistSync\PreparePlaylistSynchronization;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistOrigin;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\SpotifySourcePlaylistWriter;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\SourceSyncMutationGuard;
use App\Integrations\PlaylistSync\YouTube\PlanYouTubePlaylistMutations;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExportIntegrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_targets_cannot_activate_source_sync_through_http_or_direct_actions(): void
    {
        [$playlist] = $this->scenario(StreamingProvider::Spotify);
        $playlist->update(['origin' => PlaylistOrigin::ManagedTarget]);
        Http::fake();
        Queue::fake();

        $this->actingAs($playlist->user)
            ->postJson(route('playlist-synchronizations.prepare', $playlist))
            ->assertNotFound();
        $this->actingAs($playlist->user)
            ->getJson(route('playlist-synchronizations.show', $playlist))
            ->assertNotFound();

        try {
            app(PreparePlaylistSynchronization::class)->handle($playlist->user, $playlist);
            $this->fail('An export target must not become a source.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_previously_queued_sync_cannot_mutate_an_export_target(): void
    {
        [$playlist, $sync, $run] = $this->scenario(StreamingProvider::Spotify);
        PlaylistItem::factory()->for($playlist)->create(['catalog_id' => 'preserved']);
        $playlist->update(['origin' => PlaylistOrigin::ManagedTarget]);
        Http::fake();
        Queue::fake();

        $this->assertNull(app(DispatchPlaylistSynchronization::class)->handle($sync->id, PlaylistSyncTrigger::Manual));
        app(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame(['preserved'], $playlist->items()->pluck('catalog_id')->all());
        $this->assertSame('failed', $run->refresh()->state);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_direct_source_pull_cannot_replace_export_snapshot(): void
    {
        [$playlist] = $this->scenario(StreamingProvider::Spotify);
        $playlist->update(['origin' => PlaylistOrigin::ManagedTarget]);
        PlaylistItem::factory()->for($playlist)->create(['catalog_id' => 'preserved']);

        try {
            app(ApplySourcePlaylistToBank::class)->handle($playlist->id, new SourcePlaylistSnapshot([]));
            $this->fail('The export snapshot must not be writable by source synchronization.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(['preserved'], $playlist->items()->pluck('catalog_id')->all());
    }

    #[DataProvider('revocations')]
    public function test_spotify_rechecks_authority_before_mutating(string $change): void
    {
        [$playlist, $sync, $run, $account] = $this->scenario(StreamingProvider::Spotify);
        $access = $this->access($account);
        $guard = new SourceSyncMutationGuard($run->id, $account->id, $playlist->user_id, $account->credential_version, $access);
        match ($change) {
            'unlink' => $account->forceFill(['refresh_token' => null])->save(),
            'relink' => $account->increment('credential_version'),
            'disable' => $sync->update(['status' => PlaylistSyncStatus::Disabled]),
            'target' => $playlist->update(['origin' => PlaylistOrigin::ManagedTarget]),
        };
        Http::fake();

        $result = (new SpotifySourcePlaylistWriter(new SpotifySourcePlaylistReader))->write(
            $playlist->source_playlist_id,
            new SourcePlaylistSnapshot([]),
            [['catalog_id' => 'a', 'catalog_uri' => 'spotify:track:a']],
            $access,
            $run,
            $guard,
        );

        $this->assertInstanceOf(SourceSyncFailure::class, $result);
        Http::assertNothingSent();
    }

    public static function revocations(): array
    {
        return [['unlink'], ['relink'], ['disable'], ['target']];
    }

    public function test_youtube_stops_after_unlink_between_mutations(): void
    {
        [$playlist, $sync, $run, $account] = $this->scenario(StreamingProvider::YouTube);
        $access = $this->access($account);
        $guard = new SourceSyncMutationGuard($run->id, $account->id, $playlist->user_id, $account->credential_version, $access);
        Http::fake(function (Request $request) use ($account, $playlist) {
            if ($request->method() === 'DELETE') {
                $account->forceFill(['refresh_token' => null])->save();

                return Http::response([], 204);
            }
            if (str_contains($request->url(), '/playlists?')) {
                return Http::response(['items' => [[
                    'id' => $playlist->source_playlist_id,
                    'etag' => 'revision',
                    'snippet' => ['channelId' => 'owner'],
                    'contentDetails' => ['itemCount' => 0],
                ]]]);
            }

            return Http::response(['items' => []]);
        });

        $result = (new YouTubeSourcePlaylistWriter(new YouTubeSourcePlaylistReader, new PlanYouTubePlaylistMutations))->write(
            $playlist->source_playlist_id,
            new SourcePlaylistSnapshot(['a'], providerItemIdentifiers: ['item-a']),
            [['catalog_id' => 'b']],
            $access,
            $run,
            $guard,
        );

        $this->assertSame(SourceSyncFailure::ReconnectRequired, $result);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() !== 'GET'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_export_scopes_preserve_existing_spotify_grants_while_sync_requests_public_write(): void
    {
        $this->assertNotContains('playlist-modify-public', StreamingProvider::Spotify->exportScopes());
        $this->assertContains('playlist-modify-public', StreamingProvider::Spotify->requiredScopes());
        $this->assertSame(StreamingProvider::YouTube->requiredScopes(), StreamingProvider::YouTube->exportScopes());
    }

    #[DataProvider('providers')]
    public function test_refreshed_access_carries_expiry_and_rotated_credential_version(StreamingProvider $provider): void
    {
        $this->freezeTime();
        [$playlist, , , $account] = $this->scenario($provider);
        Http::fake(['*' => Http::response([
            'access_token' => 'access-canary',
            'refresh_token' => 'replacement-canary',
            'scope' => implode(' ', $provider->requiredScopes()),
            'expires_in' => 3600,
        ])]);
        $version = $account->credential_version;
        $observed = null;

        $result = app(WithStreamingAccess::class)->handle(
            $playlist->user,
            $account,
            $provider->requiredScopes(),
            function (StreamingAccessContext $access) use (&$observed): void {
                $observed = $access;
            },
        );

        $this->assertTrue($result->successful);
        $this->assertSame($version + 1, $observed->credentialVersion);
        $this->assertSame($account->fresh()->credential_version, $observed->credentialVersion);
        $this->assertEquals(now()->addHour()->toDateTimeImmutable(), $observed->expiresAt);
    }

    public static function providers(): array
    {
        return [[StreamingProvider::Spotify], [StreamingProvider::YouTube]];
    }

    /** @return array{Playlist, PlaylistSynchronization, PlaylistSyncRun, StreamingAccount} */
    private function scenario(StreamingProvider $provider): array
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->create([
            'provider' => $provider,
            'provider_account_id' => 'owner',
            'scopes' => $provider->requiredScopes(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => $provider,
            'source_playlist_id' => 'playlist-canary',
            'streaming_account_id' => $account->id,
        ]);
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'status' => PlaylistSyncStatus::Enabled,
            'streaming_account_id' => $account->id,
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create(['state' => 'running']);

        return [$playlist, $sync, $run, $account];
    }

    private function access(StreamingAccount $account): StreamingAccessContext
    {
        return new StreamingAccessContext($account->provider, $account->provider_account_id, 'token-canary', credentialVersion: $account->credential_version);
    }
}
