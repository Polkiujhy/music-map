<?php

namespace Tests\Unit\Integrations\StreamingAccounts;

use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\SpotifyOAuthGateway;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyOAuthGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.streaming_accounts.spotify', [
            'client_id' => 'spotify-client',
            'client_secret' => 'spotify-secret',
            'redirect_uri' => 'https://music-map.example.test/integrations/spotify/callback',
        ]);
    }

    public function test_it_builds_the_exact_authorization_contract(): void
    {
        parse_str((string) parse_url((new SpotifyOAuthGateway)->authorizationUrl('state-canary'), PHP_URL_QUERY), $query);

        $this->assertSame('spotify-client', $query['client_id']);
        $this->assertSame('state-canary', $query['state']);
        $this->assertSame('https://music-map.example.test/integrations/spotify/callback', $query['redirect_uri']);
        $this->assertSame(
            'playlist-modify-private playlist-read-collaborative playlist-read-private user-read-private',
            $query['scope'],
        );
    }

    public function test_exchange_refresh_identity_and_noop_revoke_keep_tokens_ephemeral(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'scope' => 'user-read-private playlist-read-private playlist-read-collaborative playlist-modify-private'])
            ->push(['access_token' => 'access-two', 'scope' => 'user-read-private playlist-read-private playlist-read-collaborative playlist-modify-private'])
            ->push(['account_id' => 'stable-account', 'display_name' => 'Canary']);

        $gateway = new SpotifyOAuthGateway;
        $exchange = $gateway->exchange('code-canary');
        $refresh = $gateway->refresh('refresh-one');
        $identity = $gateway->identity('access-two');

        $this->assertInstanceOf(StreamingGrant::class, $exchange);
        $this->assertSame('refresh-one', $exchange->refreshToken);
        $this->assertInstanceOf(StreamingGrant::class, $refresh);
        $this->assertNull($refresh->refreshToken);
        $this->assertInstanceOf(StreamingIdentity::class, $identity);
        $this->assertSame('stable-account', $identity->accountId);
        $this->assertNull($gateway->revoke('refresh-one'));
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://accounts.spotify.com/api/token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('spotify-client:spotify-secret')));
    }

    public function test_spotify_forbidden_is_a_neutral_access_failure(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'dashboard secret']], 403)]);

        $this->assertSame(
            StreamingOAuthFailure::AccessUnavailable,
            (new SpotifyOAuthGateway)->identity('access-canary'),
        );
    }
}
