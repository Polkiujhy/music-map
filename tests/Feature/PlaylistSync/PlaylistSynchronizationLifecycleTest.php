<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\Playlists\RefreshYouTubePlaylistMetadata as RefreshMetadata;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\DisableDependentStreamingSynchronizations;
use App\Jobs\RefreshYouTubePlaylistMetadata;
use App\Models\Playlist;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlaylistSynchronizationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unlink_seam_disables_automatic_sync_and_cancels_dispatchable_runs_without_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->youtube()->create();
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::YouTube,
            'streaming_account_id' => $account->id,
        ]);
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'automatic_enabled' => true,
            'next_check_at' => now(),
        ]);
        $pending = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create(['state' => 'pending']);

        $this->app->make(DisableDependentStreamingSynchronizations::class)->handle($account);
        $account->delete();
        StreamingAccount::factory()->for($user)->youtube()->create();

        $this->assertSame('cancelled', $pending->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Disabled, $sync->refresh()->status);
        $this->assertFalse($sync->automatic_enabled);
        $this->assertNull($sync->next_check_at);
        $this->assertNull($sync->streaming_account_id);
        Http::assertNothingSent();

        $this->app->make(RunPlaylistSynchronization::class)->handle($pending->id);

        $this->assertSame('cancelled', $pending->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Disabled, $sync->refresh()->status);
    }

    public function test_historical_youtube_refresh_job_skips_playlist_owned_by_sync_coordinator(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $playlist = Playlist::factory()->create([
            'source_provider' => StreamingProvider::YouTube,
            'provider_metadata_refreshed_at' => now()->subDays(29),
        ]);
        PlaylistSynchronization::factory()->for($playlist)->create([
            'status' => PlaylistSyncStatus::Attention,
        ]);

        $this->artisan('playlists:refresh-youtube-metadata')->assertSuccessful();
        Queue::assertNotPushed(RefreshYouTubePlaylistMetadata::class);
        (new RefreshYouTubePlaylistMetadata($playlist->id))->handle($this->app->make(RefreshMetadata::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('playlists', ['id' => $playlist->id]);
    }

    public function test_pruning_removes_only_old_terminal_runs_and_preserves_active_and_recent_runs(): void
    {
        $sync = PlaylistSynchronization::factory()->create();
        $old = now()->subDays(15);
        $oldCompleted = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'state' => 'completed',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $oldFailed = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'state' => 'failed',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $oldPending = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'state' => 'pending',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $oldRunning = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'state' => 'running',
            'created_at' => $old,
            'updated_at' => $old,
        ]);
        $recentCompleted = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create(['state' => 'completed']);

        config()->set('playlist-sync.run_retention_days', 14);
        config()->set('playlist-sync.prune_batch_size', 1);
        $this->artisan('playlist-sync:prune-runs')->assertSuccessful();

        $this->assertDatabaseMissing('playlist_sync_runs', ['id' => $oldCompleted->id]);
        $this->assertDatabaseMissing('playlist_sync_runs', ['id' => $oldFailed->id]);
        $this->assertDatabaseHas('playlist_sync_runs', ['id' => $oldPending->id]);
        $this->assertDatabaseHas('playlist_sync_runs', ['id' => $oldRunning->id]);
        $this->assertDatabaseHas('playlist_sync_runs', ['id' => $recentCompleted->id]);
    }
}
