<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\Playlists\ImportPlaylist;
use App\Actions\Playlists\ReplaceImportedPlaylist;
use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\Data\PlaylistSnapshot;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DurableExportTargetImportTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('knownTargets')]
    public function test_known_unmaterialized_and_historical_targets_cannot_be_imported(StreamingProvider $provider, string $status): void
    {
        Http::fake();
        $export = PlaylistExport::factory()->create(['target_provider' => $provider]);
        $snapshot = $this->snapshot($provider);
        PlaylistExportTargetAttempt::factory()->for($export, 'playlistExport')->create([
            'target_provider' => $provider,
            'provider_playlist_id' => $snapshot->providerPlaylistId,
            'canonical_url' => $snapshot->canonicalUrl,
            'status' => $status,
        ]);
        $owner = $export->sourcePlaylist->user;

        $result = app(ImportPlaylist::class)->handle($owner, $snapshot->canonicalUrl);
        $this->assertSame(ImportFailureCode::ExportTargetConflict, $result->failureCode);
        $this->assertSame(ImportFailureCode::ExportTargetConflict, app(ReplaceImportedPlaylist::class)->handle($owner, $snapshot));
        $this->assertDatabaseCount('playlists', 1);
        $this->assertNull($export->fresh()->target_playlist_id);
        Http::assertNothingSent();
    }

    #[DataProvider('midReadCollisions')]
    public function test_target_discovered_during_remote_read_returns_typed_failure_without_creating_a_source(bool $localEditsConfirmed, bool $materialized): void
    {
        config(['services.playlist_import.youtube.api_key' => 'canary-api-key']);
        $export = PlaylistExport::factory()->create(['target_provider' => StreamingProvider::YouTube]);
        $owner = $export->sourcePlaylist->user;
        $snapshot = $this->snapshot(StreamingProvider::YouTube);
        $published = false;
        Http::fake(function (Request $request) use ($export, $owner, $snapshot, $materialized, &$published) {
            if (! $published) {
                $published = true;
                if ($materialized) {
                    Playlist::factory()->for($owner)->create([
                        'origin' => PlaylistOrigin::ManagedTarget,
                        'source_provider' => $snapshot->provider,
                        'source_playlist_id' => $snapshot->providerPlaylistId,
                    ]);
                } else {
                    PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
                        'target_provider' => $snapshot->provider,
                        'provider_playlist_id' => $snapshot->providerPlaylistId,
                        'canonical_url' => $snapshot->canonicalUrl,
                    ]);
                }
            }

            return str_contains($request->url(), '/playlists?')
                ? Http::response(['items' => [[
                    'id' => $snapshot->providerPlaylistId,
                    'etag' => 'canary-revision',
                    'snippet' => ['title' => 'Remote target', 'channelId' => 'owner'],
                    'contentDetails' => ['itemCount' => 0],
                ]]])
                : Http::response(['items' => [], 'pageInfo' => ['totalResults' => 0]]);
        });

        $result = app(ImportPlaylist::class)->handle($owner, $snapshot->canonicalUrl, $localEditsConfirmed);

        $this->assertTrue($published);
        $this->assertSame(ImportFailureCode::ExportTargetConflict, $result->failureCode);
        $this->assertFalse($result->successful);
        $this->assertSame(0, $owner->playlists()->sourceOnly()->where('source_playlist_id', $snapshot->providerPlaylistId)->count());
        $this->assertDatabaseCount('playlists', $materialized ? 2 : 1);
    }

    public function test_reservation_is_scoped_to_the_user_and_provider(): void
    {
        $export = PlaylistExport::factory()->create(['target_provider' => StreamingProvider::Spotify]);
        $snapshot = $this->snapshot(StreamingProvider::Spotify);
        PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'target_provider' => StreamingProvider::Spotify,
            'provider_playlist_id' => $snapshot->providerPlaylistId,
            'canonical_url' => $snapshot->canonicalUrl,
        ]);

        $otherOwner = User::factory()->create();
        $import = app(ReplaceImportedPlaylist::class)->handle($otherOwner, $snapshot);
        $this->assertInstanceOf(Playlist::class, $import);
        $this->assertSame($otherOwner->id, $import->user_id);

        $otherProvider = new PlaylistSnapshot(StreamingProvider::YouTube, $snapshot->providerPlaylistId,
            null, 'https://www.youtube.com/playlist?list='.$snapshot->providerPlaylistId,
            null, 'Other provider', null, now()->toDateTimeImmutable(), []);
        $this->assertInstanceOf(Playlist::class, app(ReplaceImportedPlaylist::class)->handle($export->sourcePlaylist->user, $otherProvider));
    }

    public static function knownTargets(): array
    {
        $cases = [];
        foreach (StreamingProvider::cases() as $provider) {
            foreach ([PlaylistExportTargetAttempt::STATUS_RESOLVED, PlaylistExportTargetAttempt::STATUS_ABANDONED] as $status) {
                $cases[$provider->value.'_'.$status] = [$provider, $status];
            }
        }

        return $cases;
    }

    public static function midReadCollisions(): array
    {
        return [
            'locator' => [false, false],
            'locator_after_local_edits_confirmation' => [true, false],
            'materialized' => [false, true],
            'materialized_after_local_edits_confirmation' => [true, true],
        ];
    }

    private function snapshot(StreamingProvider $provider): PlaylistSnapshot
    {
        $id = $provider === StreamingProvider::Spotify ? '1234567890123456789012' : 'PL_canary';

        return new PlaylistSnapshot($provider, $id, 'owner',
            $provider === StreamingProvider::Spotify ? 'https://open.spotify.com/playlist/'.$id
                : 'https://www.youtube.com/playlist?list='.$id,
            null, 'Target', null, now()->toDateTimeImmutable(), []);
    }
}
