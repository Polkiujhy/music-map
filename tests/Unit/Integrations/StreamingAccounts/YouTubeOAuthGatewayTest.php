<?php

namespace Tests\Unit\Integrations\StreamingAccounts;

use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Integrations\StreamingAccounts\YouTubeOAuthGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YouTubeOAuthGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.streaming_accounts.youtube', [
            'client_id' => 'youtube-client',
            'client_secret' => 'youtube-secret',
            'redirect_uri' => 'https://music-map.example.test/integrations/youtube/callback',
        ]);
    }

    public function test_it_builds_offline_consent_authorization_url(): void
    {
        parse_str((string) parse_url((new YouTubeOAuthGateway)->authorizationUrl('state-canary'), PHP_URL_QUERY), $query);

        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('https://www.googleapis.com/auth/youtube', $query['scope']);
        $this->assertSame('state-canary', $query['state']);
    }

    public function test_exchange_refresh_single_channel_identity_and_revoke(): void
    {
        Http::fakeSequence()
            ->push(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'scope' => 'https://www.googleapis.com/auth/youtube'])
            ->push(['access_token' => 'access-two'])
            ->push(['items' => [['id' => 'channel-one', 'snippet' => ['title' => 'Canary channel']]]])
            ->push([], 200);

        $gateway = new YouTubeOAuthGateway;

        $this->assertInstanceOf(StreamingGrant::class, $gateway->exchange('code-canary'));
        $this->assertInstanceOf(StreamingGrant::class, $gateway->refresh('refresh-one'));
        $this->assertInstanceOf(StreamingIdentity::class, $gateway->identity('access-two'));
        $this->assertNull($gateway->revoke('refresh-one'));
        Http::assertSentCount(4);
    }

    public function test_identity_requires_exactly_one_selected_channel_and_quota_is_closed(): void
    {
        Http::fakeSequence()
            ->push(['items' => []])
            ->push(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403);

        $gateway = new YouTubeOAuthGateway;

        $this->assertSame(StreamingOAuthFailure::InvalidResponse, $gateway->identity('access-one'));
        $this->assertSame(StreamingOAuthFailure::QuotaExceeded, $gateway->identity('access-two'));
    }
}
