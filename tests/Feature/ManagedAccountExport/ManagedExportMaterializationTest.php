<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Actions\ManagedAccountExport\MaterializeManagedExportPlaylist;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistItem;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistMetadata;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistReference;
use App\Integrations\ManagedAccountExport\Data\ManagedPlaylistSnapshot;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedExportMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stable_exact_snapshot_creates_one_ordered_managed_target_and_succeeds_atomically(): void
    {
        [$operation, $metadata, $snapshot] = $this->fixture();
        $action = app(MaterializeManagedExportPlaylist::class);

        $this->assertTrue($action->handle($operation->id, 1, $metadata, $snapshot));
        $this->assertFalse($action->handle($operation->id, 1, $metadata, $snapshot));

        $operation->refresh();
        $target = $operation->playlistExport->fresh()->targetPlaylist()->firstOrFail();
        $this->assertSame(ExportOperationStatus::Succeeded, $operation->status);
        $this->assertSame(PlaylistOrigin::ManagedTarget, $target->origin);
        $this->assertSame('managed-owner', $target->source_account_id);
        $this->assertSame(['target-one', 'target-two'], $target->items()->pluck('catalog_id')->all());
        $this->assertSame([0, 1], $target->items()->pluck('position')->all());
        $this->assertSame(1, Playlist::query()->where('source_playlist_id', 'provider-target')->count());
    }

    public function test_compatible_import_is_adopted_only_for_the_same_technical_owner(): void
    {
        [$operation, $metadata, $snapshot] = $this->fixture();
        $import = Playlist::factory()->for($operation->user)->create([
            'origin' => PlaylistOrigin::Imported,
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'provider-target',
            'source_account_id' => 'managed-owner',
        ]);

        $this->assertTrue(app(MaterializeManagedExportPlaylist::class)->handle($operation->id, 1, $metadata, $snapshot));

        $this->assertSame($import->id, $operation->playlistExport->fresh()->target_playlist_id);
        $this->assertSame(PlaylistOrigin::ManagedTarget, $import->fresh()->origin);
    }

    public function test_owner_collision_fails_closed_without_overwriting_the_import(): void
    {
        [$operation, $metadata, $snapshot] = $this->fixture();
        $import = Playlist::factory()->for($operation->user)->create([
            'origin' => PlaylistOrigin::Imported,
            'source_provider' => StreamingProvider::Spotify,
            'source_playlist_id' => 'provider-target',
            'source_account_id' => 'different-owner',
            'name' => 'Keep me',
        ]);

        $this->assertFalse(app(MaterializeManagedExportPlaylist::class)->handle($operation->id, 1, $metadata, $snapshot));

        $this->assertSame('Keep me', $import->fresh()->name);
        $this->assertSame(PlaylistOrigin::Imported, $import->fresh()->origin);
        $this->assertSame(ExportOperationStatus::Processing, $operation->fresh()->status);
    }

    /** @return array{ExportOperation, ManagedPlaylistMetadata, ManagedPlaylistSnapshot} */
    private function fixture(): array
    {
        $source = Playlist::factory()->create(['source_provider' => StreamingProvider::YouTube]);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'managed-owner',
        ]);
        foreach ([0, 1] as $position) {
            ExportReviewItem::factory()->for($review)->create([
                'position' => $position,
                'source_occurrence_id' => 'source-'.$position,
                'target_catalog_id' => 'target-'.($position === 0 ? 'one' : 'two'),
                'target_catalog_uri' => 'spotify:track:'.($position === 0 ? 'one' : 'two'),
                'target_title' => 'Target '.$position,
            ]);
        }
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_provider' => StreamingProvider::Spotify,
            'target_account_id' => 'managed-owner',
        ]);
        $operation = ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create([
            'status' => ExportOperationStatus::Processing,
            'attempt_generation' => 1,
            'automatic_claim_count' => 1,
        ]);
        $marker = '00000000-0000-0000-0000-000000000000';
        $metadata = new ManagedPlaylistMetadata(
            'Managed copy',
            "Managed by Music Map.\nmusic-map-managed-export:v1:{$marker}",
            $marker,
        );
        $reference = new ManagedPlaylistReference(
            StreamingProvider::Spotify,
            'provider-target',
            'https://open.spotify.com/playlist/provider-target',
            'managed-owner',
        );
        $snapshot = new ManagedPlaylistSnapshot($reference, $metadata, 'private', [
            new ManagedPlaylistItem('target-one', 'spotify:track:one', 0, 'remote-one'),
            new ManagedPlaylistItem('target-two', 'spotify:track:two', 1, 'remote-two'),
        ], 'revision-one');

        return [$operation, $metadata, $snapshot];
    }
}
