<?php

namespace Tests\Feature\ManagedAccountExport;

use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistOrigin;
use App\Enums\StreamingProvider;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\PlaylistExport;
use App\Models\PlaylistExportTargetAttempt;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManagedExportBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.managed_export.providers.spotify.account_id' => 'runtime-owner',
            'services.managed_export.providers.youtube.account_id' => 'youtube-runtime-owner',
        ]);
    }

    public function test_bank_uses_bounded_latest_per_playlist_provider_and_keeps_historical_link_after_account_change(): void
    {
        [$operation, $source] = $this->operation(ExportOperationStatus::Succeeded, 'historical-owner');
        foreach (range(1, 12) as $index) {
            $review = ExportReview::factory()->for($source->user)->for($source)->create([
                'status' => ExportReviewStatus::Confirmed,
                'target_account_id' => 'historical-owner',
            ]);
            ExportOperation::factory()->for($source->user)->for($review)->for($operation->playlistExport, 'playlistExport')->create([
                'status' => ExportOperationStatus::Failed,
                'created_at' => now()->subMinutes(20 + $index),
                'completed_at' => now()->subMinutes(20 + $index),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($source->user)->get(route('bank.index'))->assertOk();

        $response
            ->assertSee('Przeniesiona — zarządzana przez music-map')
            ->assertSeeHtml('href="https://open.spotify.com/playlist/historical-target"')
            ->assertSee('Status i historyczny link pozostają dostępne')
            ->assertDontSee('Spróbuj ponownie');

        $rankedQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'row_number() over'),
        );
        $this->assertCount(2, $rankedQueries);
    }

    public function test_active_operation_wins_over_latest_terminal_and_blocks_new_review_offer(): void
    {
        [$terminal, $source] = $this->operation(ExportOperationStatus::Succeeded, 'runtime-owner');
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'target_account_id' => 'runtime-owner',
        ]);
        ExportOperation::factory()->for($source->user)->for($review)->for($terminal->playlistExport, 'playlistExport')->create([
            'status' => ExportOperationStatus::PartialFailed,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($source->user)->get(route('bank.index'))
            ->assertOk()
            ->assertSee('Nie udało się dokończyć przenoszenia');

        $this->assertSame(
            1,
            substr_count($response->getContent(), '>Rozpocznij przegląd</button>'),
            'The active Spotify operation must block only the matching Spotify offer; YouTube remains available.',
        );
    }

    public function test_materialized_target_is_rendered_once_read_only_and_direct_mutation_routes_fail_closed(): void
    {
        [$operation, $source] = $this->operation(ExportOperationStatus::Succeeded, 'runtime-owner', target: true);
        $target = $operation->playlistExport->targetPlaylist;

        $response = $this->actingAs($source->user)->get(route('bank.index'))->assertOk();
        $response
            ->assertSee('Tylko do odczytu')
            ->assertSee('Otwórz przeniesioną playlistę')
            ->assertDontSee(route('bank.playlists.edit', $target), false);
        $this->assertSame(1, substr_count($response->getContent(), 'Managed target snapshot'));

        $this->actingAs($source->user)->get(route('bank.playlists.edit', $target))->assertNotFound();
        $this->actingAs($source->user)->post(route('bank.playlists.reimport', $target), [
            'confirm_reimport' => '1',
        ])->assertNotFound();
        $this->actingAs($source->user)->post(route('export-reviews.store', $target), [
            'target_provider' => StreamingProvider::YouTube->value,
            'destination_type' => 'managed',
        ])->assertNotFound();
    }

    /** @return array{ExportOperation, Playlist} */
    private function operation(ExportOperationStatus $status, string $owner, bool $target = false): array
    {
        $source = Playlist::factory()->create(['name' => 'Source playlist']);
        PlaylistItem::factory()->for($source)->create(['position' => 0]);
        $review = ExportReview::factory()->for($source->user)->for($source)->create([
            'status' => ExportReviewStatus::Confirmed,
            'target_account_id' => $owner,
        ]);
        $export = PlaylistExport::factory()->for($source, 'sourcePlaylist')->create([
            'target_account_id' => $owner,
        ]);
        PlaylistExportTargetAttempt::factory()->resolved()->for($export, 'playlistExport')->create([
            'provider_playlist_id' => 'historical-target',
            'canonical_url' => 'https://open.spotify.com/playlist/historical-target',
        ]);
        if ($target) {
            $snapshot = Playlist::factory()->for($source->user)->create([
                'origin' => PlaylistOrigin::ManagedTarget,
                'source_provider' => StreamingProvider::Spotify,
                'source_playlist_id' => 'historical-target',
                'source_account_id' => $owner,
                'canonical_source_url' => 'https://open.spotify.com/playlist/historical-target',
                'name' => 'Managed target snapshot',
            ]);
            PlaylistItem::factory()->for($snapshot)->create(['position' => 0]);
            $export->update(['target_playlist_id' => $snapshot->id]);
        }
        $operation = ExportOperation::factory()->for($source->user)->for($review)->for($export, 'playlistExport')->create([
            'status' => $status,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ]);

        return [$operation->load('playlistExport.targetPlaylist'), $source->load('user')];
    }
}
