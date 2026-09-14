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
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlaylistSynchronizationFailureTest extends TestCase
{
    use RefreshDatabase;

    public function beginDatabaseTransaction(): void
    {
        // YouTube admission deliberately owns and commits its transaction.
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('youtube_write_admissions')->delete();
        DB::table('youtube_write_quota_states')->updateOrInsert(
            ['singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY],
            ['quota_day' => null, 'admitted_count' => 0, 'daily_limit' => null],
        );
        config()->set('services.youtube_write_admission.daily_limit', '10');

        $this->beforeApplicationDestroyed(function (): void {
            DB::table('youtube_write_admissions')->delete();
            User::query()->delete();
        });
    }

    /** @return array<string, array{int, SourceSyncFailure, string, string}> */
    public static function providerFailures(): array
    {
        return [
            'expired provider token' => [401, SourceSyncFailure::Unauthorized, 'failed', 'attention'],
            'rate limited' => [429, SourceSyncFailure::RateLimited, 'pending', 'enabled'],
            'provider unavailable' => [503, SourceSyncFailure::ProviderUnavailable, 'pending', 'enabled'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_preserve_the_confirmed_baseline(
        int $status,
        SourceSyncFailure $expected,
        string $runState,
        string $syncStatus,
    ): void {
        [$sync, $run] = $this->spotifyScenario();
        $before = $this->baseline($sync);
        $this->app->instance(WithStreamingAccess::class, new SuccessfulFailureMatrixAccess(StreamingProvider::Spotify));
        Http::preventStrayRequests();
        Http::fakeSequence()->push([], $status);

        $result = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame($expected, $result);
        $this->assertSame($runState, $run->refresh()->state);
        $this->assertSame($syncStatus, $sync->refresh()->status->value);
        $this->assertSame($before, $this->baseline($sync));
        Http::assertSentCount(1);
    }

    public function test_refresh_token_rejection_requires_reconnect_without_provider_http_or_baseline_change(): void
    {
        [$sync, $run] = $this->spotifyScenario();
        $before = $this->baseline($sync);
        $this->app->instance(WithStreamingAccess::class, new RejectedFailureMatrixAccess);
        Http::preventStrayRequests();
        Http::fake();

        $result = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertSame(SourceSyncFailure::ReconnectRequired, $result);
        $this->assertSame('failed', $run->refresh()->state);
        $this->assertSame(PlaylistSyncStatus::Attention, $sync->refresh()->status);
        $this->assertSame($before, $this->baseline($sync));
        Http::assertNothingSent();
    }

    public function test_admission_refusal_leaves_the_complete_bank_source_and_baseline_state_unchanged(): void
    {
        [$playlist, $sync, $run] = $this->youtubeScenario(['video-a'], ['video-b']);
        $remote = new StatefulYouTubePlaylist(['video-a']);
        $quotaDay = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        DB::table('youtube_write_quota_states')->where('singleton_key', YouTubeWriteQuotaState::GLOBAL_KEY)->update([
            'quota_day' => $quotaDay,
            'admitted_count' => 1,
            'daily_limit' => 1,
        ]);
        $before = $this->completeState($playlist, $sync);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $remote->respond($request));

        $result = $this->writer()->write(
            'playlist-canary',
            $remote->snapshot(),
            $playlist->items()->orderBy('position')->get()->map->only([
                'catalog_id', 'catalog_uri',
            ])->all(),
            $this->youtubeAccess(),
            $run,
        );

        $this->assertSame(SourceSyncFailure::OverLimit, $result);
        $this->assertSame($before, $this->completeState($playlist, $sync));
        $this->assertSame(['video-a'], $remote->identifiers());
        $this->assertDatabaseCount('youtube_write_admissions', 0);
        $this->assertDatabaseHas('youtube_write_quota_states', [
            'singleton_key' => YouTubeWriteQuotaState::GLOBAL_KEY,
            'admitted_count' => 1,
            'daily_limit' => 1,
        ]);
        Http::assertNothingSent();
    }

    /** @return array<string, array{int}> */
    public static function writeStages(): array
    {
        return [
            'delete' => [0],
            'reorder' => [1],
            'insert' => [2],
        ];
    }

    #[DataProvider('writeStages')]
    public function test_each_partial_youtube_write_stage_retries_to_desired_state_under_one_admission(int $failureStage): void
    {
        $run = PlaylistSyncRun::factory()->create(['checkpoint' => null]);
        $remote = new StatefulYouTubePlaylist(['a', 'b', 'c'], $failureStage);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $remote->respond($request));
        $desired = [['catalog_id' => 'b'], ['catalog_id' => 'd'], ['catalog_id' => 'a']];

        $first = $this->writer()->write(
            'playlist-canary',
            $remote->snapshot(),
            $desired,
            $this->youtubeAccess(),
            $run,
        );
        $second = $this->writer()->write(
            'playlist-canary',
            $remote->snapshot(),
            $desired,
            $this->youtubeAccess(),
            $run->refresh(),
        );

        $this->assertSame(SourceSyncFailure::ProviderUnavailable, $first);
        $this->assertInstanceOf(SourcePlaylistSnapshot::class, $second);
        $this->assertSame(['b', 'd', 'a'], $second->itemIdentifiers);
        $this->assertSame(['b', 'd', 'a'], $remote->identifiers());
        $this->assertSame(3, $run->refresh()->checkpoint['index']);
        $this->assertDatabaseCount('youtube_write_admissions', 1);
        $this->assertSame(10, $remote->requestCount);
        Http::assertSentCount(10);
    }

    public function test_source_change_after_partial_youtube_push_resolves_source_wins_without_double_admission(): void
    {
        [$playlist, $sync, $run] = $this->youtubeScenario(['a', 'b', 'c'], ['b', 'd', 'a']);
        $remote = new StatefulYouTubePlaylist(['a', 'b', 'c'], 1);
        $this->app->instance(WithStreamingAccess::class, new SuccessfulFailureMatrixAccess(StreamingProvider::YouTube));
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $remote->respond($request));

        $first = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);
        $this->assertSame(SourceSyncFailure::ProviderUnavailable, $first);
        $this->assertSame('running', $run->refresh()->state);
        $this->assertDatabaseCount('youtube_write_admissions', 1);

        $remote->replaceExternally(['external-video']);
        $second = $this->app->make(RunPlaylistSynchronization::class)->handle($run->id);

        $this->assertNull($second);
        $this->assertSame('completed', $run->refresh()->state);
        $this->assertSame('pulled', $sync->refresh()->last_outcome->value);
        $this->assertSame(['external-video'], $playlist->refresh()->items()->pluck('catalog_id')->all());
        $this->assertDatabaseCount('youtube_write_admissions', 1);
        $this->assertSame(8, $remote->requestCount);
        Http::assertSentCount(8);
    }

    /** @return array{PlaylistSynchronization, PlaylistSyncRun} */
    private function spotifyScenario(): array
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
            'catalog_id' => 'track-a',
            'catalog_uri' => 'spotify:track:track-a',
        ]);
        $playlist->load('items');
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => (new FingerprintPlaylistContent)->handle($playlist),
            'baseline_source_fingerprint' => (new FingerprintSourcePlaylist)->handle(new SourcePlaylistSnapshot(['track-a'])),
            'baseline_provider_revision' => 'revision-a',
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'state' => 'pending',
        ]);

        return [$sync, $run];
    }

    /** @param list<string> $source
     * @param  list<string>  $bank
     * @return array{Playlist, PlaylistSynchronization, PlaylistSyncRun}
     */
    private function youtubeScenario(array $source, array $bank): array
    {
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
        foreach ($bank as $position => $identifier) {
            PlaylistItem::factory()->for($playlist)->create([
                'position' => $position,
                'occurrence_id' => "bank-item-{$position}",
                'catalog_id' => $identifier,
                'catalog_uri' => "https://www.youtube.com/watch?v={$identifier}",
            ]);
        }
        $playlist->load('items');
        $baselineBank = new SourcePlaylistSnapshot($source);
        $sync = PlaylistSynchronization::factory()->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'status' => PlaylistSyncStatus::Enabled,
            'baseline_bank_fingerprint' => (new FingerprintSourcePlaylist)->handle($baselineBank),
            'baseline_source_fingerprint' => (new FingerprintSourcePlaylist)->handle($baselineBank),
            'baseline_provider_revision' => 'revision-0',
        ]);
        $run = PlaylistSyncRun::factory()->for($sync, 'synchronization')->create([
            'trigger' => PlaylistSyncTrigger::Manual,
            'state' => 'pending',
        ]);

        return [$playlist, $sync, $run];
    }

    /** @return array<string, mixed> */
    private function baseline(PlaylistSynchronization $sync): array
    {
        return $sync->refresh()->only([
            'baseline_bank_fingerprint',
            'baseline_source_fingerprint',
            'baseline_provider_revision',
            'last_succeeded_at',
        ]);
    }

    /** @return array<string, mixed> */
    private function completeState(Playlist $playlist, PlaylistSynchronization $sync): array
    {
        return [
            'playlist' => $playlist->refresh()->getAttributes(),
            'items' => $playlist->items()->orderBy('position')->get()->map->getAttributes()->all(),
            'sync' => $sync->refresh()->getAttributes(),
        ];
    }

    private function writer(): YouTubeSourcePlaylistWriter
    {
        return new YouTubeSourcePlaylistWriter(
            new YouTubeSourcePlaylistReader,
            new PlanYouTubePlaylistMutations,
            $this->app->make(AdmitYouTubeWrite::class),
        );
    }

    private function youtubeAccess(): StreamingAccessContext
    {
        return new StreamingAccessContext(StreamingProvider::YouTube, 'owner-canary', 'access-canary');
    }
}

final class SuccessfulFailureMatrixAccess implements WithStreamingAccess
{
    public function __construct(private readonly StreamingProvider $provider) {}

    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        $callback(new StreamingAccessContext($this->provider, 'owner-canary', 'access-canary'));

        return StreamingAccessResult::success();
    }
}

final class RejectedFailureMatrixAccess implements WithStreamingAccess
{
    public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
    {
        return StreamingAccessResult::failure(StreamingAccessFailure::ReconnectRequired);
    }
}

final class StatefulYouTubePlaylist
{
    /** @var list<array{id:string, video_id:string}> */
    private array $items;

    private bool $failed = false;

    private int $mutationAttempt = 0;

    public int $requestCount = 0;

    /** @param list<string> $identifiers */
    public function __construct(array $identifiers, private readonly ?int $failureStage = null)
    {
        $this->replaceExternally($identifiers);
    }

    public function respond(Request $request): mixed
    {
        $this->requestCount++;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'GET' && str_ends_with($path, '/playlists')) {
            return Http::response(['items' => [[
                'id' => 'playlist-canary',
                'etag' => 'revision-'.$this->requestCount,
                'snippet' => ['channelId' => 'owner-canary'],
                'contentDetails' => ['itemCount' => count($this->items)],
            ]]]);
        }

        if ($request->method() === 'GET' && str_ends_with($path, '/playlistItems')) {
            return Http::response(['items' => array_map(
                static fn (array $item, int $position): array => [
                    'id' => $item['id'],
                    'snippet' => [
                        'position' => $position,
                        'title' => 'Video '.$item['video_id'],
                        'videoOwnerChannelTitle' => 'Creator',
                        'resourceId' => ['videoId' => $item['video_id']],
                    ],
                ],
                $this->items,
                array_keys($this->items),
            )]);
        }

        $stage = $this->mutationAttempt++;
        if (! $this->failed && $this->failureStage === $stage) {
            $this->failed = true;

            return Http::response([], 503);
        }

        if ($request->method() === 'DELETE') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->items = array_values(array_filter(
                $this->items,
                static fn (array $item): bool => $item['id'] !== ($query['id'] ?? null),
            ));

            return Http::response([], 204);
        }

        if ($request->method() === 'PUT') {
            $id = (string) $request['id'];
            $position = (int) $request['snippet']['position'];
            foreach ($this->items as $index => $item) {
                if ($item['id'] !== $id) {
                    continue;
                }
                array_splice($this->items, $index, 1);
                array_splice($this->items, $position, 0, [$item]);
                break;
            }

            return Http::response([]);
        }

        if ($request->method() === 'POST') {
            $position = (int) $request['snippet']['position'];
            $videoId = (string) $request['snippet']['resourceId']['videoId'];
            $item = ['id' => 'inserted-'.$videoId, 'video_id' => $videoId];
            array_splice($this->items, $position, 0, [$item]);

            return Http::response(['id' => $item['id']]);
        }

        return Http::response([], 500);
    }

    /** @param list<string> $identifiers */
    public function replaceExternally(array $identifiers): void
    {
        $this->items = array_map(
            static fn (string $identifier, int $position): array => [
                'id' => "remote-item-{$position}-{$identifier}",
                'video_id' => $identifier,
            ],
            $identifiers,
            array_keys($identifiers),
        );
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_column($this->items, 'video_id');
    }

    public function snapshot(): SourcePlaylistSnapshot
    {
        return new SourcePlaylistSnapshot(
            $this->identifiers(),
            'revision-'.$this->requestCount,
            'owner-canary',
            array_column($this->items, 'id'),
        );
    }
}
