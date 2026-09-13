<?php

namespace Tests\Feature\Playlists;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class PlaylistImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.playlist_import.youtube.api_key', 'api-key-canary');
    }

    public function test_guest_and_unverified_user_cannot_import(): void
    {
        Http::fake();

        $this->post(route('playlists.import'), $this->form())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->unverified()->create())
            ->post(route('playlists.import'), $this->form())
            ->assertRedirect(route('verification.notice'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_consent_is_required_before_provider_work(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->from(route('bank.index'))
            ->post(route('playlists.import'), ['playlist_url' => $this->url()])
            ->assertRedirect(route('bank.index'))
            ->assertSessionHasErrors('policy_consent')
            ->assertSessionMissing('_old_input.playlist_url');

        Http::assertNothingSent();
        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_invalid_url_makes_no_request_and_logs_only_sanitized_context(): void
    {
        Http::fake();
        Log::spy();

        $this->actingAs(User::factory()->create())
            ->post(route('playlists.import'), [
                'playlist_url' => 'https://attacker.example/private?token=sensitive',
                'policy_consent' => '1',
            ])
            ->assertRedirect(route('bank.index'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Identyfikator błędu:'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('playlists', 0);
        Log::shouldHaveReceived('warning')->once()->with(
            'playlist_import_failed',
            Mockery::on(fn (array $context): bool => array_keys($context) === ['correlation_id', 'failure_code', 'provider']
                && $context['failure_code'] === 'unsupported-provider'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sensitive')),
        );
    }

    public function test_public_youtube_import_and_reimport_use_one_private_card(): void
    {
        $user = User::factory()->create();
        Http::fakeSequence()
            ->push($this->metadata('First name', 1))
            ->push($this->items(['video-canary']))
            ->push($this->metadata('Refreshed name', 1))
            ->push($this->items(['video-canary']));

        $this->actingAs($user)->post(route('playlists.import'), $this->form())
            ->assertRedirect(route('bank.index'))
            ->assertSessionHas('status', 'Playlista została dodana do Twojego banku.');
        $this->actingAs($user)->post(route('playlists.import'), $this->form())
            ->assertSessionHas('status', 'Playlista została odświeżona w Twoim banku.');

        $this->assertDatabaseCount('playlists', 1);
        $this->assertDatabaseHas('playlists', ['user_id' => $user->id, 'name' => 'Refreshed name']);
        $this->actingAs($user)->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Refreshed name')
            ->assertSee('Źródło: YouTube')
            ->assertSee('https://www.youtube.com/playlist?list=PL_canary', false);
    }

    public function test_failed_reimport_leaves_the_previous_snapshot_unchanged(): void
    {
        $user = User::factory()->create();
        Http::fakeSequence()
            ->push($this->metadata('Original', 1))
            ->push($this->items(['video-original']))
            ->push($this->metadata('Replacement', 1))
            ->push(['items' => 'malformed']);

        $this->actingAs($user)->post(route('playlists.import'), $this->form());
        $before = Playlist::query()->with('items')->firstOrFail()->toArray();
        $this->actingAs($user)->post(route('playlists.import'), $this->form())
            ->assertSessionHas('error');

        $this->assertSame($before, Playlist::query()->with('items')->firstOrFail()->toArray());
    }

    public function test_bank_never_displays_another_users_playlist(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        Playlist::factory()->for($owner)->create(['name' => 'Foreign canary playlist']);

        $this->actingAs($viewer)->get(route('bank.index'))
            ->assertDontSee('Foreign canary playlist');
    }

    public function test_sixth_attempt_is_throttled_per_user_before_provider_work(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/playlistItems?')
            ? Http::response($this->items([]))
            : Http::response($this->metadata('Canary', 0)));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($first)->post(route('playlists.import'), $this->form())->assertRedirect();
        }

        $sentBeforeThrottle = Http::recorded()->count();
        $this->actingAs($first)->post(route('playlists.import'), $this->form())->assertTooManyRequests();
        $this->assertSame($sentBeforeThrottle, Http::recorded()->count());

        $this->actingAs($second)->post(route('playlists.import'), $this->form())->assertRedirect();
        $this->assertSame($sentBeforeThrottle + 2, Http::recorded()->count());
    }

    public function test_public_policy_pages_contain_required_youtube_and_google_notices(): void
    {
        $this->get(route('legal.terms'))
            ->assertOk()
            ->assertSee('Warunkom korzystania z YouTube')
            ->assertSee('https://www.youtube.com/t/terms', false);
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('YouTube API Services')
            ->assertSee('https://policies.google.com/privacy', false)
            ->assertSee('https://myaccount.google.com/permissions', false);
    }

    private function form(): array
    {
        return ['playlist_url' => $this->url(), 'policy_consent' => '1'];
    }

    private function url(): string
    {
        return 'https://www.youtube.com/playlist?list=PL_canary';
    }

    private function metadata(string $name, int $count): array
    {
        return ['items' => [[
            'id' => 'PL_canary',
            'etag' => 'revision-canary',
            'snippet' => ['title' => $name, 'description' => 'Description', 'channelId' => 'channel-canary'],
            'contentDetails' => ['itemCount' => $count],
        ]]];
    }

    private function items(array $videoIds): array
    {
        return [
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
        ];
    }
}
