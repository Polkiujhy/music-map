<?php

namespace Tests\Feature\Playlists;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Actions\Playlists\RefreshYouTubePlaylistMetadata as RefreshMetadata;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Jobs\RefreshYouTubePlaylistMetadata;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class YouTubeMetadataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-13 12:00:00');
        config()->set('services.playlist_import.youtube.api_key', 'api-key-canary');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_ignores_fresh_records_and_dispatches_at_twenty_eight_days(): void
    {
        Queue::fake();
        $fresh = $this->playlistAt(now()->subDays(28)->addSecond(), 'Fresh');
        $aging = $this->playlistAt(now()->subDays(28), 'Aging');

        Artisan::call('playlists:refresh-youtube-metadata');

        Queue::assertNotPushed(RefreshYouTubePlaylistMetadata::class, fn ($job): bool => $job->playlistId === $fresh->id);
        Queue::assertPushed(RefreshYouTubePlaylistMetadata::class, fn ($job): bool => $job->playlistId === $aging->id);
    }

    public function test_job_is_unique_by_playlist_and_serializes_no_secret_or_source_url(): void
    {
        $playlist = $this->playlistAt(now()->subDays(28), 'Aging');
        $first = new RefreshYouTubePlaylistMetadata($playlist->id);
        $duplicate = new RefreshYouTubePlaylistMetadata($playlist->id);
        $serialized = serialize($first);

        $this->assertInstanceOf(ShouldBeUnique::class, $first);
        $this->assertSame($first->uniqueId(), $duplicate->uniqueId());
        $this->assertSame((string) $playlist->id, $first->uniqueId());
        $this->assertStringNotContainsString($playlist->canonical_source_url, $serialized);
        $this->assertStringNotContainsString('api-key-canary', $serialized);
    }

    public function test_successful_job_refresh_atomically_replaces_snapshot_without_changing_import_time(): void
    {
        $playlist = $this->playlistAt(now()->subDays(28), 'Old name');
        $importedAt = $playlist->imported_at;
        PlaylistItem::factory()->for($playlist)->create(['catalog_id' => 'old-video']);
        $this->fakeSuccessfulRead($playlist, 'New name', ['new-video']);

        (new RefreshYouTubePlaylistMetadata($playlist->id))->handle(app(RefreshMetadata::class));

        $playlist->refresh()->load('items');
        $this->assertSame('New name', $playlist->name);
        $this->assertSame(now()->toDateTimeString(), $playlist->provider_metadata_refreshed_at->toDateTimeString());
        $this->assertTrue($importedAt->equalTo($playlist->imported_at));
        $this->assertSame(['new-video'], $playlist->items->pluck('catalog_id')->all());
    }

    public function test_rate_failure_does_not_advance_freshness_and_uses_a_bounded_short_delay(): void
    {
        $this->assertRefreshFailureIsDelayedWithoutAdvancingFreshness(
            Http::response([], 429),
            ImportFailureCode::RateLimited,
            60,
        );
    }

    public function test_quota_failure_does_not_advance_freshness_and_uses_a_bounded_long_delay(): void
    {
        $this->assertRefreshFailureIsDelayedWithoutAdvancingFreshness(
            Http::response(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403),
            ImportFailureCode::QuotaLimited,
            300,
        );
    }

    private function assertRefreshFailureIsDelayedWithoutAdvancingFreshness(
        PromiseInterface $response,
        ImportFailureCode $expectedFailure,
        int $expectedDelay,
    ): void {
        $playlist = $this->playlistAt(now()->subDays(28), 'Old name');
        $originalFreshness = $playlist->provider_metadata_refreshed_at;
        Log::spy();
        Http::fake(['*' => $response]);

        $job = (new RefreshYouTubePlaylistMetadata($playlist->id))->withFakeQueueInteractions();
        $job->handle(app(RefreshMetadata::class));

        $job->assertReleased($expectedDelay);
        $this->assertTrue($originalFreshness->equalTo($playlist->fresh()->provider_metadata_refreshed_at));
        Log::shouldHaveReceived('warning')->once()->with(
            'youtube_playlist_metadata_refresh_failed',
            Mockery::on(fn (array $context): bool => array_keys($context) === ['playlist_id', 'failure_code']
                && $context['playlist_id'] === $playlist->id
                && $context['failure_code'] === $expectedFailure->value
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'api-key-canary')
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'youtube.com')),
        );
    }

    public function test_twenty_nine_day_metadata_remains_visible(): void
    {
        $user = User::factory()->create();
        $this->playlistAt(now()->subDays(29), 'Still current', $user);

        $this->actingAs($user)->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Still current')
            ->assertDontSee('dane wymagają odświeżenia');
    }

    public function test_thirty_day_metadata_is_hidden_and_purged_while_the_shell_and_link_remain(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $playlist = $this->playlistAt(now()->subDays(30), 'Expired provider name', $user);
        PlaylistItem::factory()->for($playlist)->create();
        $playlist->update(['bank_content_edited_at' => now()->subDay()]);
        $fingerprintBeforePurge = app(FingerprintPlaylistContent::class)->handle($playlist->load('items'));
        $canonicalUrl = $playlist->canonical_source_url;

        $this->actingAs($user)->get(route('bank.index'))
            ->assertOk()
            ->assertDontSee('Expired provider name')
            ->assertSee('Playlista YouTube — dane wymagają odświeżenia')
            ->assertSee($canonicalUrl, false);

        Artisan::call('playlists:refresh-youtube-metadata');

        $playlist->refresh();
        $this->assertNull($playlist->name);
        $this->assertNull($playlist->description);
        $this->assertNull($playlist->source_account_id);
        $this->assertNull($playlist->provider_revision);
        $this->assertNull($playlist->bank_content_edited_at);
        $this->assertSame($canonicalUrl, $playlist->canonical_source_url);
        $this->assertDatabaseMissing('playlist_items', ['playlist_id' => $playlist->id]);
        $this->assertNotSame(
            $fingerprintBeforePurge,
            app(FingerprintPlaylistContent::class)->handle($playlist->load('items')),
        );
        Queue::assertNothingPushed();
    }

    public function test_command_purges_every_expired_record_beyond_the_refresh_batch_limit(): void
    {
        Queue::fake();

        for ($index = 0; $index < 101; $index++) {
            $playlist = $this->playlistAt(now()->subDays(30), "Expired {$index}");
        }

        PlaylistItem::factory()->for($playlist)->create();

        Artisan::call('playlists:refresh-youtube-metadata');

        $this->assertSame(0, Playlist::query()->whereNotNull('name')->count());
        $this->assertDatabaseMissing('playlist_items', ['playlist_id' => $playlist->id]);
        Queue::assertNothingPushed();
    }

    public function test_command_does_not_rewrite_an_already_purged_shell(): void
    {
        Queue::fake();
        $playlist = $this->playlistAt(now()->subDays(30), 'Expired');
        PlaylistItem::factory()->for($playlist)->create();

        Artisan::call('playlists:refresh-youtube-metadata');

        $purgedAt = $playlist->fresh()->updated_at;
        Carbon::setTestNow(now()->addDay());

        Artisan::call('playlists:refresh-youtube-metadata');

        $playlist->refresh();
        $this->assertTrue($purgedAt->equalTo($playlist->updated_at));
        $this->assertNull($playlist->name);
        $this->assertDatabaseMissing('playlist_items', [
            'playlist_id' => $playlist->id,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_a_purged_shell_recovers_after_a_later_successful_refresh(): void
    {
        Queue::fake();
        $playlist = $this->playlistAt(now()->subDays(30), 'Expired');
        Artisan::call('playlists:refresh-youtube-metadata');
        $this->fakeSuccessfulRead($playlist, 'Recovered', ['recovered-video']);

        $result = app(RefreshMetadata::class)->handle($playlist->id);

        $this->assertInstanceOf(Playlist::class, $result);
        $this->assertSame('Recovered', $result->name);
        $this->assertSame(['recovered-video'], $result->items->pluck('catalog_id')->all());
        $this->assertSame(now()->toDateTimeString(), $result->provider_metadata_refreshed_at->toDateTimeString());
    }

    private function playlistAt(Carbon $refreshedAt, string $name, ?User $user = null): Playlist
    {
        return Playlist::factory()->for($user ?? User::factory())->create([
            'source_playlist_id' => 'PL_'.str_replace(' ', '-', $name),
            'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL_'.str_replace(' ', '-', $name),
            'source_account_id' => 'channel-canary',
            'name' => $name,
            'provider_metadata_refreshed_at' => $refreshedAt,
            'imported_at' => now()->subDays(40),
        ]);
    }

    /**
     * @param  list<string>  $videoIds
     */
    private function fakeSuccessfulRead(Playlist $playlist, string $name, array $videoIds): void
    {
        Http::fakeSequence()
            ->push(['items' => [[
                'id' => $playlist->source_playlist_id,
                'etag' => 'revision-new',
                'snippet' => ['title' => $name, 'description' => 'New description', 'channelId' => 'channel-canary'],
                'contentDetails' => ['itemCount' => count($videoIds)],
            ]]])
            ->push([
                'items' => array_map(static fn (string $videoId, int $position): array => [
                    'id' => "occurrence-{$position}",
                    'snippet' => [
                        'position' => $position,
                        'title' => "Item {$position}",
                        'videoOwnerChannelTitle' => 'Creator',
                        'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
                    ],
                ], $videoIds, array_keys($videoIds)),
                'pageInfo' => ['totalResults' => count($videoIds)],
            ]);
    }
}
