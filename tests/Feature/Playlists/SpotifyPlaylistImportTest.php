<?php

namespace Tests\Feature\Playlists;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Providers\SpotifyPlaylistReader;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpotifyPlaylistImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_linked_account_stops_before_any_provider_or_probe_request(): void
    {
        Http::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('playlists.import'), $this->form())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Połącz konto Spotify'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('playlists', 0);
    }

    public function test_linked_owner_or_collaborator_imports_through_the_s04_access_contract(): void
    {
        $account = $this->account();
        $access = new PlaylistAccessFake;
        $this->app->instance(WithStreamingAccess::class, $access);
        Http::fakeSequence()->push($this->metadata(1))->push($this->items(['track-canary']));

        $this->actingAs($account->user)
            ->post(route('playlists.import'), $this->form())
            ->assertSessionHas('status', 'Playlista została dodana do Twojego banku.');

        $playlist = Playlist::query()->with('items')->firstOrFail();
        $this->assertSame($account->id, $playlist->streaming_account_id);
        $this->assertSame($account->provider_account_id, $playlist->source_account_id);
        $this->assertSame(['track-canary'], $playlist->items->pluck('catalog_id')->all());
        $this->assertSame(SpotifyPlaylistReader::REQUIRED_SCOPES, $access->requiredScopes);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'accounts.spotify.com')
            || str_contains($request->url(), 'platform-access'));
    }

    #[DataProvider('accessFailures')]
    public function test_s04_access_failures_are_actionable_and_make_zero_playlist_requests(
        StreamingAccessFailure $failure,
        string $message,
    ): void {
        $account = $this->account();
        $this->app->instance(WithStreamingAccess::class, new PlaylistAccessFake($failure));
        Http::fake();

        $this->actingAs($account->user)
            ->post(route('playlists.import'), $this->form())
            ->assertSessionHas('error', fn (string $error): bool => str_contains($error, $message));

        Http::assertNothingSent();
        $this->assertDatabaseCount('playlists', 0);
    }

    public static function accessFailures(): array
    {
        return [
            'invalid grant' => [StreamingAccessFailure::ReconnectRequired, 'Połącz ponownie'],
            'stale CAS' => [StreamingAccessFailure::StaleCredential, 'Połącz ponownie'],
            'scope' => [StreamingAccessFailure::MissingScope, 'wymaganych uprawnień'],
            'rate' => [StreamingAccessFailure::RateLimited, 'ograniczył liczbę żądań'],
            'quota' => [StreamingAccessFailure::QuotaExceeded, 'Limit Spotify został wyczerpany'],
            'unavailable' => [StreamingAccessFailure::TemporarilyUnavailable, 'chwilowo niedostępny'],
        ];
    }

    public function test_access_context_account_mismatch_refuses_before_http(): void
    {
        $account = $this->account();
        $access = new PlaylistAccessFake(contextAccountId: 'different-account');
        $this->app->instance(WithStreamingAccess::class, $access);
        Http::fake();

        $this->actingAs($account->user)
            ->post(route('playlists.import'), $this->form())
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Połącz ponownie'));

        Http::assertNothingSent();
    }

    public function test_failed_reimport_preserves_the_existing_bank_snapshot(): void
    {
        $account = $this->account();
        $this->app->instance(WithStreamingAccess::class, new PlaylistAccessFake);
        Http::fakeSequence()
            ->push($this->metadata(1))
            ->push($this->items(['old-track']))
            ->push([], 503);

        $this->actingAs($account->user)->post(route('playlists.import'), $this->form());
        $before = Playlist::query()->with('items')->firstOrFail()->toArray();
        $this->actingAs($account->user)->post(route('playlists.import'), $this->form())
            ->assertSessionHas('error');

        $this->assertSame($before, Playlist::query()->with('items')->firstOrFail()->toArray());
    }

    public function test_unlink_nulls_the_relation_but_preserves_snapshot_and_non_secret_provenance(): void
    {
        $account = $this->account();
        $playlist = Playlist::factory()->for($account->user)->create([
            'source_provider' => StreamingProvider::Spotify,
            'streaming_account_id' => $account->id,
            'source_account_id' => $account->provider_account_id,
        ]);

        $account->delete();

        $playlist->refresh();
        $this->assertNull($playlist->streaming_account_id);
        $this->assertSame('spotify-account-canary', $playlist->source_account_id);
        $this->assertDatabaseHas('playlists', ['id' => $playlist->id]);
    }

    public function test_tokens_and_tracking_url_never_cross_the_import_boundary(): void
    {
        $account = $this->account();
        $this->app->instance(WithStreamingAccess::class, new PlaylistAccessFake(accessToken: 'access-secret-canary'));
        Http::fakeSequence()->push($this->metadata(0))->push($this->items([]));

        $this->actingAs($account->user)->post(route('playlists.import'), [
            'playlist_url' => $this->url().'?si=tracking-secret-canary',
            'policy_consent' => '1',
        ]);

        $playlist = Playlist::query()->firstOrFail();
        $database = json_encode(DB::table('playlists')->where('id', $playlist->id)->first(), JSON_THROW_ON_ERROR);
        $html = $this->actingAs($account->user)->get(route('bank.index'))->getContent();
        $this->assertStringNotContainsString('access-secret-canary', $database.$html);
        $this->assertStringNotContainsString('tracking-secret-canary', $database.$html);
        $this->assertSame($this->url(), $playlist->canonical_source_url);
    }

    private function account(): StreamingAccount
    {
        return StreamingAccount::factory()->spotify()->create([
            'provider_account_id' => 'spotify-account-canary',
            'scopes' => StreamingProvider::Spotify->requiredScopes(),
        ]);
    }

    private function form(): array
    {
        return ['playlist_url' => $this->url(), 'policy_consent' => '1'];
    }

    private function url(): string
    {
        return 'https://open.spotify.com/playlist/0123456789abcdefghijkl';
    }

    private function metadata(int $count): array
    {
        return [
            'id' => '0123456789abcdefghijkl',
            'name' => 'Spotify canary playlist',
            'description' => 'Canary description',
            'snapshot_id' => 'revision-canary',
            'tracks' => ['total' => $count],
        ];
    }

    private function items(array $trackIds): array
    {
        return [
            'items' => array_map(static fn (string $trackId): array => [
                'is_local' => false,
                'item' => [
                    'type' => 'track',
                    'id' => $trackId,
                    'uri' => "spotify:track:{$trackId}",
                    'name' => 'Canary track',
                    'artists' => [['name' => 'Canary artist']],
                    'album' => ['name' => 'Canary album'],
                    'duration_ms' => 1000,
                    'external_ids' => ['isrc' => 'CANARY000001'],
                ],
            ], $trackIds),
            'total' => count($trackIds),
            'next' => null,
        ];
    }
}

final class PlaylistAccessFake implements WithStreamingAccess
{
    /** @var list<string> */
    public array $requiredScopes = [];

    public function __construct(
        private readonly ?StreamingAccessFailure $failure = null,
        private readonly string $contextAccountId = 'spotify-account-canary',
        private readonly string $accessToken = 'access-canary',
    ) {}

    public function handle(
        User $owner,
        StreamingAccount $account,
        array $requiredScopes,
        Closure $callback,
    ): StreamingAccessResult {
        $this->requiredScopes = $requiredScopes;

        if ($this->failure !== null) {
            return StreamingAccessResult::failure($this->failure);
        }

        $callback(new StreamingAccessContext(
            StreamingProvider::Spotify,
            $this->contextAccountId,
            $this->accessToken,
        ));

        return StreamingAccessResult::success();
    }
}
