<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\ExportReviews\ResolveExportDestination;
use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingAccessResult;
use App\Integrations\StreamingAccounts\SpotifyOAuthGateway;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ResolveExportDestinationTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_destination_is_owner_scoped_and_carries_identity_and_market(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create(['market' => 'GB']);

        $destination = $this->resolver()->handle($user, $playlist, StreamingProvider::Spotify, ExportDestinationType::Linked, $account->id);

        $this->assertSame($account->id, $destination->streamingAccountId);
        $this->assertSame($account->provider_account_id, $destination->accountId);
        $this->assertSame('GB', $destination->market);
    }

    public function test_managed_destination_uses_symbolic_identity_only_without_active_linked_account(): void
    {
        config()->set('services.managed_export.providers.spotify.account_id', 'managed-canary');
        config()->set('services.managed_export.providers.spotify.market', 'pl');
        config()->set('services.platform_access.spotify.technical.account_id', 'probe-canary');
        $user = User::factory()->create();

        $destination = $this->resolver()->handle($user, Playlist::factory()->for($user)->create(), StreamingProvider::Spotify, ExportDestinationType::Managed);

        $this->assertSame('managed-canary', $destination->accountId);
        $this->assertSame('PL', $destination->market);
        $this->assertNull($destination->streamingAccountId);
    }

    public function test_existing_spotify_account_without_market_is_hydrated_using_ephemeral_access(): void
    {
        Http::fake(['https://api.spotify.com/v1/me' => Http::response(['id' => 'spotify-canary', 'display_name' => 'Canary', 'country' => 'de'])]);
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create(['provider_account_id' => 'spotify-canary', 'market' => null]);

        $destination = $this->resolver()->handle($user, Playlist::factory()->for($user)->create(), StreamingProvider::Spotify, ExportDestinationType::Linked, $account->id);

        $this->assertSame('DE', $destination->market);
        $this->assertSame('DE', $account->fresh()->market);
    }

    public function test_reconnect_foreign_account_missing_managed_market_and_same_source_pair_are_rejected(): void
    {
        $user = User::factory()->create();
        $foreign = StreamingAccount::factory()->spotify()->create();
        $reconnect = StreamingAccount::factory()->for($user)->youtube()->reconnectRequired()->create();
        $playlist = Playlist::factory()->for($user)->create();

        foreach ([
            fn () => $this->resolver()->handle($user, $playlist, StreamingProvider::Spotify, ExportDestinationType::Linked, $foreign->id),
            fn () => $this->resolver()->handle($user, $playlist, StreamingProvider::YouTube, ExportDestinationType::Linked, $reconnect->id),
            function () use ($user, $playlist): void {
                config()->set('services.managed_export.providers.spotify.account_id', 'managed');
                config()->set('services.managed_export.providers.spotify.market', null);
                $this->resolver()->handle($user, $playlist, StreamingProvider::Spotify, ExportDestinationType::Managed);
            },
            function () use ($user): void {
                config()->set('services.managed_export.providers.youtube.account_id', 'same-account');
                $source = Playlist::factory()->for($user)->create([
                    'source_provider' => StreamingProvider::YouTube,
                    'source_playlist_id' => 'PL-same-account-source',
                    'source_account_id' => 'same-account',
                    'canonical_source_url' => 'https://www.youtube.com/playlist?list=PL-same-account-source',
                ]);
                $this->resolver()->handle($user, $source, StreamingProvider::YouTube, ExportDestinationType::Managed);
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected destination validation to fail.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function resolver(): ResolveExportDestination
    {
        $access = new class implements WithStreamingAccess
        {
            public function handle(User $owner, StreamingAccount $account, array $requiredScopes, Closure $callback): StreamingAccessResult
            {
                $failure = $callback(new StreamingAccessContext($account->provider, $account->provider_account_id, 'access-canary'));

                return $failure === null ? StreamingAccessResult::success() : StreamingAccessResult::failure($failure);
            }
        };

        return new ResolveExportDestination($access, new SpotifyOAuthGateway);
    }
}
