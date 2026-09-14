<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\PlaylistSync\FingerprintSourcePlaylist;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class RunPlaylistSynchronizationPostgresTest extends TestCase
{
    public function test_concurrent_runs_serialize_revalidation_and_leave_one_source_wins_state(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This race test requires PostgreSQL.');
        }
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
            'catalog_id' => 'original', 'catalog_uri' => 'spotify:track:original',
        ]);
        $playlist->load('items');
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => (new FingerprintPlaylistContent)->handle($playlist),
            'baseline_source_fingerprint' => (new FingerprintSourcePlaylist)->handle(new SourcePlaylistSnapshot(['original'])),
        ]);
        $runs = collect(range(1, 2))->map(fn (): PlaylistSyncRun => PlaylistSyncRun::factory()
            ->for($sync, 'synchronization')->create([
                'trigger' => PlaylistSyncTrigger::Manual,
                'direction' => PlaylistSyncDirection::NoOp,
                'state' => 'pending',
            ]));
        $barrier = sys_get_temp_dir().'/music-map-source-sync-race-'.Str::uuid();
        File::ensureDirectoryExists($barrier, 0700);
        DB::disconnect();
        $pids = [];

        try {
            foreach ($runs as $index => $run) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork source sync contender.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        $this->app->instance(WithStreamingAccess::class, new PostgresRunSyncAccessFake);
                        $track = "source-{$index}";
                        Http::preventStrayRequests();
                        Http::fakeSequence()
                            ->push(['id' => 'playlist-canary', 'owner' => ['id' => 'owner-canary'],
                                'snapshot_id' => "revision-{$index}", 'tracks' => ['total' => 1]])
                            ->push(['items' => [['item' => ['type' => 'track', 'id' => $track,
                                'uri' => "spotify:track:{$track}", 'name' => 'Track', 'artists' => []]]], 'next' => null]);
                        touch("{$barrier}/ready-{$index}");
                        while (! is_file("{$barrier}/go")) {
                            usleep(1000);
                        }
                        $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents("{$barrier}/error-{$index}", $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while (count(glob("{$barrier}/ready-*")) !== 2) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Source sync contenders did not reach the barrier.');
                }
                usleep(1000);
            }
            touch("{$barrier}/go");
            foreach ($pids as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $error = is_file("{$barrier}/error-{$index}") ? file_get_contents("{$barrier}/error-{$index}") : '';
                $this->assertSame(0, pcntl_wexitstatus($status), $error);
            }
            DB::purge();
            $this->assertSame(0, $sync->runs()->whereIn('state', ['pending', 'running'])->count());
            $this->assertContains($playlist->refresh()->items()->value('catalog_id'), ['source-0', 'source-1']);
        } finally {
            touch("{$barrier}/go");
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge();
            User::query()->whereKey($user->id)->delete();
            File::deleteDirectory($barrier);
        }
    }
}

final class PostgresRunSyncAccessFake implements WithStreamingAccess
{
    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext(StreamingProvider::Spotify, 'owner-canary', 'access-canary'));

        return StreamingAccessResult::success();
    }
}
