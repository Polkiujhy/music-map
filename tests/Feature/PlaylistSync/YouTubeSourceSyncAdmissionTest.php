<?php

namespace Tests\Feature\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\PlaylistSync\FingerprintSourcePlaylist;
use App\Actions\PlaylistSync\RunPlaylistSynchronization;
use App\Enums\PlaylistSyncStatus;
use App\Enums\PlaylistSyncTrigger;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistReader;
use App\Integrations\PlaylistSync\Providers\YouTubeSourcePlaylistWriter;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\PlaylistSync\YouTube\PlanYouTubePlaylistMutations;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\YouTubeWriteAdmission\Contracts\AdmitYouTubeWrite;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\PlaylistSynchronization;
use App\Models\PlaylistSyncRun;
use App\Models\StreamingAccount;
use App\Models\User;
use App\Models\YouTubeWriteQuotaState;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeSourceSyncAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // Admission must own its transaction.
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null],
        );
        config()->set('services.youtube_write_admission.daily_limit', '5');
        $this->beforeApplicationDestroyed(function (): void {
            DB::table('youtube_write_admissions')->delete();
            User::query()->delete();
        });
    }

    public function test_no_op_does_not_reserve_or_write(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $run = PlaylistSyncRun::factory()->create();

        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot(['video-a'], ['item-a']),
            [['catalog_id' => 'video-a']],
            $this->access(),
            $run,
        );

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $result);
        $this->assertDatabaseCount('youtube_write_admissions', 0);
        Http::assertNothingSent();
    }

    public function test_source_changed_pull_does_not_reserve_a_youtube_write(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->youtube()->create([
            'provider_account_id' => 'owner-canary',
            'scopes' => StreamingProvider::YouTube->requiredScopes(),
        ]);
        $playlist = Playlist::factory()->for($user)->create([
            'source_provider' => StreamingProvider::YouTube,
            'source_playlist_id' => 'playlist-canary',
            'streaming_account_id' => $account->id,
        ]);
        PlaylistItem::factory()->for($playlist)->create(['catalog_id' => 'bank-video']);
        $playlist->load('items');
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => (new FingerprintPlaylistContent)->handle($playlist),
            'baseline_source_fingerprint' => (new FingerprintSourcePlaylist)->handle($this->snapshot(['old-video'], ['old-item'])),
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'state' => 'pending',
        ]);
        $this->app->instance(WithStreamingAccess::class, new YouTubeAdmissionSyncAccess);
        Http::fakeSequence()
            ->push($this->metadata(1))
            ->push(['items' => [$this->item('new-item', 'new-video', 0)]]);

        $outcome = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertNull($outcome);
        $this->assertSame('completed', $run->refresh()->state);
        $this->assertSame('new-video', $playlist->refresh()->items()->value('catalog_id'));
        $this->assertDatabaseCount('youtube_write_admissions', 0);
    }

    public function test_real_push_reserves_once_and_retry_reuses_the_run_operation_id(): void
    {
        Http::preventStrayRequests();
        $run = PlaylistSyncRun::factory()->create(['checkpoint' => null]);
        Http::fakeSequence()
            ->push([], 500)
            ->push(['id' => 'item-a'])
            ->push($this->metadata(1))
            ->push(['items' => [$this->item('item-a', 'video-a', 0)]]);

        $first = $this->writer()->write(
            'playlist-canary',
            $this->snapshot([], []),
            [['catalog_id' => 'video-a']],
            $this->access(),
            $run,
        );

        $this->assertSame(SourceSyncFailure::ProviderUnavailable, $first);
        $this->assertDatabaseHas('youtube_write_admissions', [
            'operation_type' => 'source-sync',
            'operation_id' => $run->operation_id,
        ]);

        $second = $this->writer()->write(
            'playlist-canary',
            $this->snapshot([], []),
            [['catalog_id' => 'video-a']],
            $this->access(),
            $run->refresh(),
        );

        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $second);
        $this->assertSame(['video-a'], $second->itemIdentifiers);
        $this->assertDatabaseCount('youtube_write_admissions', 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_refused_admission_performs_no_mutation_and_creates_no_reservation(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('services.youtube_write_admission.daily_limit', '1');
        $quotaDay = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        DB::table('youtube_write_quota_states')->where('singleton_key', YouTubeWriteQuotaState::GLOBAL_KEY)->update([
            'quota_day' => $quotaDay,
            'admitted_count' => 1,
            'daily_limit' => 1,
        ]);
        $run = PlaylistSyncRun::factory()->create();

        $result = $this->writer()->write(
            'playlist-canary',
            $this->snapshot([], []),
            [['catalog_id' => 'video-a']],
            $this->access(),
            $run,
        );

        $this->assertSame(SourceSyncFailure::WriteAdmissionLimited, $result);
        $this->assertDatabaseCount('youtube_write_admissions', 0);
        Http::assertNothingSent();
    }

    private function writer(): YouTubeSourcePlaylistWriter
    {
        return new YouTubeSourcePlaylistWriter(
            new YouTubeSourcePlaylistReader,
            new PlanYouTubePlaylistMutations,
            $this->app->make(AdmitYouTubeWrite::class),
        );
    }

    private function access(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::YouTube, 'owner-canary', 'access-canary');
    }

    /** @param list<string> $ids
     * @param  list<string>  $providerIds
     */
    private function snapshot(array $ids, array $providerIds): SourcePlaylistSnapshot
    {
        return new SourcePlaylistSnapshot($ids, providerItemIdentifiers: $providerIds);
    }

    private function metadata(int $count): array
    {
        return ['items' => [[
            'id' => 'playlist-canary',
            'etag' => 'revision-canary',
            'snippet' => ['channelId' => 'owner-canary'],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    private function item(string $itemId, string $videoId, int $position): array
    {
        return ['id' => $itemId, 'snippet' => [
            'position' => $position,
            'title' => 'Video',
            'videoOwnerChannelTitle' => 'Creator',
            'resourceId' => ['videoId' => $videoId],
        ]];
    }
}

final class YouTubeAdmissionSyncAccess implements WithStreamingAccess
{
    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext(StreamingProvider::YouTube, 'owner-canary', 'access-canary'));

        return StreamingAccessResult::success();
    }
}
