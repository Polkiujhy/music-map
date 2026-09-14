<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmExportReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.platform_access.spotify.technical.account_id' => 'managed-spotify',
            'services.platform_access.spotify.technical.market' => 'GB',
        ]);
    }

    public function test_confirmation_atomically_removes_and_reindexes_bank_but_keeps_unavailable_item(): void
    {
        [$review, $items] = $this->readyReview([
            ExportMatchStatus::Matched,
            ExportMatchStatus::Unavailable,
            ExportMatchStatus::Suspicious,
        ]);

        $manifest = app(ConfirmExportReview::class)->handle($review->user, $review, [
            $items[1]->id => 'keep',
            $items[2]->id => 'remove',
        ]);

        $this->assertInstanceOf(ConfirmedExportManifest::class, $manifest);
        $this->assertSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
        $this->assertNotNull($review->fresh()->confirmed_at);
        $this->assertNotNull($review->playlist->fresh()->bank_content_edited_at);
        $this->assertSame([0, 1], $review->playlist->items()->pluck('position')->all());
        $this->assertSame(['source-0', 'source-1'], $review->playlist->items()->pluck('catalog_id')->all());
        $this->assertDatabaseHas('playlist_items', ['catalog_id' => 'source-1', 'is_available' => true]);
        $this->assertDatabaseMissing('playlist_items', ['catalog_id' => 'source-2']);
        $this->assertSame([[
            'position' => 0,
            'catalog_id' => 'target-0',
            'catalog_uri' => 'spotify:track:0',
        ]], $manifest->items);
    }

    public function test_empty_manifest_and_incomplete_decisions_are_rejected_without_mutating_bank(): void
    {
        [$emptyReview, $emptyItems] = $this->readyReview([ExportMatchStatus::Unavailable]);

        try {
            app(ConfirmExportReview::class)->handle($emptyReview->user, $emptyReview, [$emptyItems[0]->id => 'keep']);
            $this->fail('An empty export manifest must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decisions', $exception->errors());
        }

        $this->assertSame(ExportReviewStatus::Ready, $emptyReview->fresh()->status);
        $this->assertSame(['source-0'], $emptyReview->playlist->items()->pluck('catalog_id')->all());

        [$incompleteReview] = $this->readyReview([ExportMatchStatus::Matched, ExportMatchStatus::Suspicious]);
        try {
            app(ConfirmExportReview::class)->handle($incompleteReview->user, $incompleteReview, []);
            $this->fail('Every suspicious result requires a decision.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertSame(ExportReviewStatus::Ready, $incompleteReview->fresh()->status);
        $this->assertSame(['source-0', 'source-1'], $incompleteReview->playlist->items()->pluck('catalog_id')->all());
    }

    public function test_double_submit_is_idempotent_and_returns_the_same_frozen_manifest(): void
    {
        [$review, $items] = $this->readyReview([ExportMatchStatus::Matched, ExportMatchStatus::Suspicious]);
        $action = app(ConfirmExportReview::class);

        $first = $action->handle($review->user, $review, [$items[1]->id => 'keep']);
        $confirmedAt = $review->fresh()->confirmed_at;
        $second = $action->handle($review->user, $review->fresh(), [$items[1]->id => 'remove']);

        $this->assertEquals($first, $second);
        $this->assertTrue($confirmedAt->equalTo($review->fresh()->confirmed_at));
        $this->assertNull($review->playlist->fresh()->bank_content_edited_at);
        $this->assertSame(['target-0', 'target-1'], array_column($second->items, 'catalog_id'));
        $this->assertSame(['source-0', 'source-1'], $review->playlist->items()->pluck('catalog_id')->all());
    }

    public function test_confirmation_uses_a_decision_already_persisted_by_the_component(): void
    {
        [$review, $items] = $this->readyReview([ExportMatchStatus::Matched, ExportMatchStatus::Suspicious]);
        $items[1]->forceFill(['decision' => ExportReviewDecision::Keep])->save();

        $manifest = app(ConfirmExportReview::class)->handle($review->user, $review, []);

        $this->assertSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
        $this->assertSame(['target-0', 'target-1'], array_column($manifest->items, 'catalog_id'));
    }

    /**
     * @param  list<ExportMatchStatus>  $statuses
     * @return array{ExportReview, list<ExportReviewItem>}
     */
    private function readyReview(array $statuses): array
    {
        $playlist = Playlist::factory()->create();
        $reviewItems = [];

        foreach ($statuses as $position => $status) {
            PlaylistItem::factory()->for($playlist)->create([
                'position' => $position,
                'occurrence_id' => 'occurrence-'.$position,
                'catalog_id' => 'source-'.$position,
                'catalog_uri' => 'source:track:'.$position,
                'title' => 'Source '.$position,
                'creators' => ['Source artist'],
                'album' => 'Source album',
                'duration_milliseconds' => 180000,
                'isrc' => null,
                'is_available' => true,
            ]);
        }

        $playlist->load(['user', 'items']);
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => ExportReviewStatus::Ready,
            'target_account_id' => 'managed-spotify',
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);

        foreach ($statuses as $position => $status) {
            $source = $playlist->items[$position];
            $reviewItems[] = ExportReviewItem::factory()->for($review)->create([
                'position' => $position,
                'source_occurrence_id' => $source->occurrence_id,
                'source_catalog_id' => $source->catalog_id,
                'source_catalog_uri' => $source->catalog_uri,
                'source_title' => $source->title,
                'source_creators' => $source->creators,
                'source_album' => $source->album,
                'source_duration_milliseconds' => $source->duration_milliseconds,
                'source_isrc' => $source->isrc,
                'source_is_available' => $source->is_available,
                'match_status' => $status,
                'target_catalog_id' => $status === ExportMatchStatus::Unavailable ? null : 'target-'.$position,
                'target_catalog_uri' => $status === ExportMatchStatus::Unavailable ? null : 'spotify:track:'.$position,
                'target_title' => $status === ExportMatchStatus::Unavailable ? null : 'Target '.$position,
                'target_creators' => $status === ExportMatchStatus::Unavailable ? null : ['Target artist'],
            ]);
        }

        return [$review, $reviewItems];
    }
}
