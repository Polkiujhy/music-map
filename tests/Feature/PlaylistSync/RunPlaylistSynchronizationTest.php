<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\PlaylistSync\DisableAccountPlaylistSynchronizations;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncOutcome;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Jobs\RunPlaylistSynchronization as RunPlaylistSynchronizationJob;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunPlaylistSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_only_advances_the_confirmed_base_and_timestamps(): void
    {
        [$playlist, $sync, $run] = $this->scenario();
        $this->fakeRead('track-a', 'revision-a');

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame('completed', $run->refresh()->state);
        $this->assertSame(PlaylistSyncOutcome::NoOp, $sync->refresh()->last_outcome);
        $this->assertNotNull($sync->last_succeeded_at);
        $this->assertSame('track-a', $playlist->items()->first()->catalog_id);
    }

    public function test_source_change_pulls_atomically_and_preserves_the_streaming_account(): void
    {
        [$playlist, $sync, $run, $account] = $this->scenario();
        $this->fakeRead('track-source', 'revision-source');

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame(['track-source'], $playlist->refresh()->items()->pluck('catalog_id')->all());
        $this->assertSame($account->id, $playlist->streaming_account_id);
        $this->assertNull($playlist->bank_content_edited_at);
        $this->assertSame(PlaylistSyncOutcome::Pulled, $sync->refresh()->last_outcome);
    }

    public function test_bank_change_pushes_once_and_only_advances_the_base_after_post_read(): void
    {
        [$playlist, $sync, $run] = $this->scenario();
        $playlist->items()->first()->update(['catalog_id' => 'track-bank', 'catalog_uri' => 'spotify:track:track-bank']);
        $playlist->update(['bank_content_edited_at' => now()]);
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push($this->metadata('revision-a'))
            ->push($this->items('track-a'))
            ->push(['snapshot_id' => 'revision-pushed'])
            ->push($this->metadata('revision-pushed'))
            ->push($this->items('track-bank'));

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame(PlaylistSyncOutcome::Pushed, $sync->refresh()->last_outcome);
        $this->assertSame('revision-pushed', $sync->baseline_provider_revision);
        $this->assertNull($playlist->refresh()->bank_content_edited_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request['uris'] === ['spotify:track:track-bank']);
    }

    public function test_bank_change_during_push_preserves_the_edit_and_queues_another_run(): void
    {
        Queue::fake();
        [$playlist, $sync, $run] = $this->scenario();
        $playlist->items()->first()->update([
            'catalog_id' => 'track-pushed',
            'catalog_uri' => 'spotify:track:track-pushed',
        ]);
        $playlist->update(['bank_content_edited_at' => now()]);
        $playlist->refresh()->load('items');
        $pushedFingerprint = (new FingerprintPlaylistContent)->handle($playlist);
        $responses = [
            $this->metadata('revision-a'),
            $this->items('track-a'),
            ['snapshot_id' => 'revision-pushed'],
            $this->metadata('revision-pushed'),
            $this->items('track-pushed'),
        ];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($playlist, &$responses) {
            if ($request->method() === 'PUT') {
                $playlist->items()->first()->update([
                    'catalog_id' => 'track-edited-during-push',
                    'catalog_uri' => 'spotify:track:track-edited-during-push',
                ]);
                $playlist->update(['bank_content_edited_at' => now()->addSecond()]);
            }

            return Http::response(array_shift($responses));
        });

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame('completed', $run->refresh()->state);
        $this->assertSame(PlaylistSyncOutcome::Pushed, $sync->refresh()->last_outcome);
        $this->assertSame($pushedFingerprint, $sync->baseline_bank_fingerprint);
        $this->assertNotNull($playlist->refresh()->bank_content_edited_at);
        $this->assertSame('track-edited-during-push', $playlist->items()->value('catalog_id'));
        $this->assertSame('pending', $sync->runs()->latest('id')->value('state'));
        $this->assertSame(2, $sync->runs()->count());
        Queue::assertPushed(RunPlaylistSynchronizationJob::class, 1);
    }

    public function test_conflict_is_resolved_source_wins_and_provider_http_never_runs_in_a_transaction(): void
    {
        [$playlist, $sync, $run] = $this->scenario();
        $playlist->items()->first()->update(['catalog_id' => 'track-bank', 'catalog_uri' => 'spotify:track:track-bank']);
        $playlist->update(['bank_content_edited_at' => now()]);
        Http::preventStrayRequests();
        $responses = [$this->metadata('revision-source'), $this->items('track-source')];
        $testHarnessTransactionLevel = DB::transactionLevel();
        Http::fake(function () use (&$responses, $testHarnessTransactionLevel) {
            $this->assertSame($testHarnessTransactionLevel, DB::transactionLevel());

            return Http::response(array_shift($responses));
        });

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame(['track-source'], $playlist->refresh()->items()->pluck('catalog_id')->all());
        $this->assertSame(PlaylistSyncOutcome::Pulled, $sync->refresh()->last_outcome);
    }

    public function test_unlink_during_provider_write_keeps_the_run_cancelled_and_sync_disabled(): void
    {
        [$playlist, $sync, $run, $account] = $this->scenario();
        $playlist->items()->first()->update([
            'catalog_id' => 'track-bank',
            'catalog_uri' => 'spotify:track:track-bank',
        ]);
        $playlist->update(['bank_content_edited_at' => now()]);
        $responses = [
            $this->metadata('revision-a'),
            $this->items('track-a'),
            ['snapshot_id' => 'revision-pushed'],
            $this->metadata('revision-pushed'),
            $this->items('track-bank'),
        ];
        $disable = $this->app->make(DisableAccountPlaylistSynchronizations::class);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($account, $disable, &$responses) {
            if ($request->method() === 'PUT') {
                $disable->handle($account);
                $account->delete();
            }

            return Http::response(array_shift($responses));
        });

        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame('cancelled', $run->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Disabled, $sync->refresh()->status);
        $this->assertFalse($sync->automatic_enabled);
        $this->assertNull($sync->streaming_account_id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    /** @return array{Playlist, PlaylistSynchronization, PlaylistSyncRun, StreamingAccount} */
    private function scenario(): array
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create([
            'provider_account_id' => 'owner-canary',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'playlist-canary',
            'streaming_account_id' => $account->id,
        ]);
        PlaylistItem::factory()->for($playlist)->create([
            'catalog_id' => 'track-a', 'catalog_uri' => 'spotify:track:track-a',
        ]);
        $playlist->load('items');
        $baseline = (new FingerprintPlaylistContent)->handle($playlist);
        $sourceBaseline = hash('sha256', "source-playlist:v1\n[\"track-a\"]");
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => $baseline,
            'baseline_source_fingerprint' => $sourceBaseline,
            'baseline_provider_revision' => 'revision-a',
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'direction' => PlaylistSyncDirection::NoOp,
            'state' => 'pending',
        ]);
        $this->app->instance(WithStreamingAccess::class, new RunSyncAccessFake);

        return [$playlist, $sync, $run, $account];
    }

    private function fakeRead(string $track, string $revision): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->metadata($revision))->push($this->items($track));
    }

    private function metadata(string $revision): array
    {
        return ['id' => 'playlist-canary', 'owner' => ['id' => 'owner-canary'],
            'snapshot_id' => $revision, 'tracks' => ['total' => 1]];
    }

    private function items(string $track): array
    {
        return ['items' => [['item' => ['type' => 'track', 'id' => $track,
            'uri' => "spotify:track:{$track}", 'name' => 'Track', 'artists' => []]]], 'next' => null];
    }
}

final class RunSyncAccessFake implements WithStreamingAccess
{
    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext(StreamingProvider::Spotify, 'owner-canary', 'access-canary'));

        return StreamingAccessResult::success();
    }
}
