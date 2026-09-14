<?php

namespace Tests\Unit\Integrations\ManagedAccountExport;

use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedAccessContext;
use App\Integrations\ManagedAccountExport\Data\ManagedMarkerLookupStatus;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
use App\Integrations\ManagedAccountExport\ManagedPlaylistMetadataFactory;
use App\Integrations\ManagedAccountExport\ManagedProviderFailure;
use App\Integrations\ManagedAccountExport\Providers\SpotifyManagedPlaylistGateway;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyManagedPlaylistGatewayTest extends TestCase
{
    private const MARKER = '01234567-89ab-cdef-0123-456789abcdef';

    public function test_create_is_private_non_collaborative_and_returns_certain_owner_reference(): void
    {
        Http::fake(['api.spotify.com/*' => Http::response($this->playlist(), 201)]);
        $gateway = new SpotifyManagedPlaylistGateway;

        $reference = $gateway->create($this->access(), $this->metadata());

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $reference);
        $this->assertSame('technical-owner', $reference->ownerAccountId);
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://api.spotify.com/v1/me/playlists', $request->url());
            $this->assertSame([
                'name' => $this->metadata()->title,
                'description' => $this->metadata()->description,
                'public' => false,
                'collaborative' => false,
            ], $request->data());

            return true;
        });
    }

    public function test_marker_recovery_paginates_to_completion_and_never_uses_title(): void
    {
        Http::fakeSequence('api.spotify.com/*')
            ->push(['items' => [['name' => 'same title', 'description' => 'no marker']], 'next' => 'https://api.spotify.com/v1/me/playlists?limit=50&offset=50'])
            ->push(['items' => [$this->playlist()], 'next' => null]);

        $lookup = (new SpotifyManagedPlaylistGateway)->findByMarker($this->access(), self::MARKER);

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $lookup);
        $this->assertSame(ManagedMarkerLookupStatus::One, $lookup->status);
        $this->assertSame('playlist-id', $lookup->matches[0]->providerPlaylistId);
        Http::assertSentCount(2);
    }

    public function test_reconcile_full_replaces_at_most_twenty_uris_and_verifies_exact_state(): void
    {
        $uris = ['spotify:track:a', 'spotify:track:b', 'spotify:track:a'];
        Http::fakeSequence('api.spotify.com/*')
            ->push($this->playlist())
            ->push($this->spotifyItems([]))
            ->push([], 200)
            ->push(['snapshot_id' => 'revision-2'], 200)
            ->push($this->playlist(['snapshot_id' => 'revision-2']))
            ->push($this->spotifyItems($uris));

        $result = (new SpotifyManagedPlaylistGateway)->reconcile(
            $this->access(),
            $this->reference(),
            $this->metadata(),
            $uris,
        );

        $this->assertNotInstanceOf(ManagedProviderFailure::class, $result);
        $this->assertTrue($result->exact);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://api.spotify.com/v1/playlists/playlist-id/items'
            && $request->data() === ['uris' => $uris]);
    }

    public function test_server_failure_after_create_is_closed_as_ambiguous_mutation(): void
    {
        Http::fake(['api.spotify.com/*' => Http::response(['error' => ['message' => 'secret-canary']], 503)]);

        $failure = (new SpotifyManagedPlaylistGateway)->create($this->access(), $this->metadata());

        $this->assertInstanceOf(ManagedProviderFailure::class, $failure);
        $this->assertSame(ManagedExportFailureCode::AmbiguousMutation, $failure->code);
        $this->assertStringNotContainsString('secret-canary', serialize($failure));
    }

    private function access(): ManagedAccessContext
    {
        return new ManagedAccessContext(StreamingProvider::Spotify, 'technical-owner', 'token-canary', new DateTimeImmutable('+5 minutes'), '123e4567-e89b-42d3-a456-426614174000');
    }

    private function metadata(): ManagedPlaylistMetadata
    {
        return (new ManagedPlaylistMetadataFactory)->make(StreamingProvider::Spotify, 'Bank title', 'Description', self::MARKER);
    }

    private function reference(): ManagedPlaylistReference
    {
        return new ManagedPlaylistReference(StreamingProvider::Spotify, 'playlist-id', 'https://open.spotify.com/playlist/playlist-id', 'technical-owner');
    }

    /** @param array<string, mixed> $extra */
    private function playlist(array $extra = []): array
    {
        return [
            'id' => 'playlist-id',
            'name' => $this->metadata()->title,
            'description' => $this->metadata()->description,
            'owner' => ['id' => 'technical-owner'],
            'external_urls' => ['spotify' => 'https://open.spotify.com/playlist/playlist-id'],
            'public' => false,
            'collaborative' => false,
            ...$extra,
        ];
    }

    /** @param list<string> $uris */
    private function spotifyItems(array $uris): array
    {
        return [
            'items' => array_map(fn (string $uri): array => ['item' => ['id' => substr($uri, strlen('spotify:track:')), 'uri' => $uri]], $uris),
            'next' => null,
        ];
    }
}
