<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Integrations\ExportMatching\Providers\SpotifyCatalogSearch;
use App\Integrations\ExportMatching\Providers\YouTubeCatalogSearch;
use App\Integrations\ExportMatching\YouTubeSearchBudget;
use App\Jobs\PrepareExportReview;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use App\Models\StreamingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExportReviewRaceTest extends TestCase
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

    public function test_direct_edit_or_reimport_invalidates_the_review_before_confirmation(): void
    {
        $edited = $this->readyReview();
        $edited->playlist->items()->firstOrFail()->update(['title' => 'Edited in another tab']);
        $this->assertStale($edited);

        $reimported = $this->readyReview();
        $reimported->playlist->items()->delete();
        PlaylistItem::factory()->for($reimported->playlist)->create([
            'position' => 0,
            'catalog_id' => 'replacement-source',
            'title' => 'Reimported item',
        ]);
        $this->assertStale($reimported);
    }

    public function test_unlinked_target_and_expired_review_cannot_be_confirmed(): void
    {
        $linked = $this->readyReview(linked: true);
        $linked->streamingAccount->delete();
        $this->assertStale($linked->fresh());

        $expired = $this->readyReview();
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->assertStale($expired->fresh());
    }

    public function test_parallel_tabs_commit_exactly_one_confirmation(): void
    {
        $review = $this->readyReview();
        $firstTab = ExportReview::query()->findOrFail($review->id);
        $secondTab = ExportReview::query()->findOrFail($review->id);
        $action = app(ConfirmExportReview::class);

        $firstManifest = $action->handle($review->user, $firstTab, []);
        $confirmedAt = $review->fresh()->confirmed_at;
        $secondManifest = $action->handle($review->user, $secondTab, []);

        $this->assertEquals($firstManifest, $secondManifest);
        $this->assertTrue($confirmedAt->equalTo($review->fresh()->confirmed_at));
        $this->assertSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
    }

    public function test_late_matching_job_cannot_modify_a_confirmed_review(): void
    {
        Http::preventStrayRequests();
        $review = $this->readyReview();
        app(ConfirmExportReview::class)->handle($review->user, $review, []);
        $before = $review->items()->firstOrFail()->only(['match_status', 'target_catalog_id', 'target_catalog_uri', 'decision']);

        (new PrepareExportReview($review->id))->handle(
            app(SpotifyCatalogSearch::class),
            app(YouTubeCatalogSearch::class),
            app(YouTubeSearchBudget::class),
            app(FingerprintPlaylistContent::class),
        );

        $this->assertSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
        $this->assertSame($before, $review->items()->firstOrFail()->only(array_keys($before)));
        Http::assertNothingSent();
    }

    private function assertStale(ExportReview $review): void
    {
        try {
            app(ConfirmExportReview::class)->handle($review->user, $review, []);
            $this->fail('A stale review must not be confirmed.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNotSame(ExportReviewStatus::Confirmed, $review->fresh()->status);
        $this->assertNull($review->fresh()->confirmed_at);
    }

    private function readyReview(bool $linked = false): ExportReview
    {
        $playlist = Playlist::factory()->create();
        $source = PlaylistItem::factory()->for($playlist)->create([
            'position' => 0,
            'catalog_id' => 'source-0',
            'catalog_uri' => 'source:track:0',
            'title' => 'Source 0',
            'creators' => ['Source artist'],
            'album' => 'Source album',
            'duration_milliseconds' => 180000,
            'isrc' => null,
        ]);
        $playlist->load(['user', 'items']);
        $account = $linked
            ? StreamingAccount::factory()->for($playlist->user)->spotify()->create([
                'provider_account_id' => 'linked-spotify',
                'market' => 'GB',
            ])
            : null;
        $review = ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'streaming_account_id' => $account?->id,
            'target_provider' => StreamingProvider::Spotify,
            'destination_type' => $linked ? ExportDestinationType::Linked : ExportDestinationType::Managed,
            'target_account_id' => $account?->provider_account_id ?? 'managed-spotify',
            'target_market' => 'GB',
            'status' => ExportReviewStatus::Ready,
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'source_occurrence_id' => $source->occurrence_id,
            'source_catalog_id' => $source->catalog_id,
            'source_catalog_uri' => $source->catalog_uri,
            'source_title' => $source->title,
            'source_creators' => $source->creators,
            'source_album' => $source->album,
            'source_duration_milliseconds' => $source->duration_milliseconds,
            'source_isrc' => $source->isrc,
            'source_is_available' => true,
            'match_status' => ExportMatchStatus::Matched,
            'target_catalog_id' => 'target-0',
            'target_catalog_uri' => 'spotify:track:0',
        ]);

        return $review->load(['user', 'playlist', 'streamingAccount']);
    }
}
