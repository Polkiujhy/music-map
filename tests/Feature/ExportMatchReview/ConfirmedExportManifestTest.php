<?php

namespace Tests\Feature\ExportMatchReview;

use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use ReflectionClass;
use Tests\TestCase;

class ConfirmedExportManifestTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_confirmed_review_exposes_an_ordered_exact_manifest_without_rematching(): void
    {
        Http::preventStrayRequests();
        $review = ExportReview::factory()->create([
            'status' => ExportReviewStatus::Confirmed,
            'confirmed_at' => now(),
            'source_fingerprint' => str_repeat('a', 64),
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 2,
            'match_status' => ExportMatchStatus::Unavailable,
            'target_catalog_id' => null,
            'target_catalog_uri' => null,
            'decision' => ExportReviewDecision::Keep,
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 1,
            'match_status' => ExportMatchStatus::Suspicious,
            'target_catalog_id' => 'removed-target',
            'target_catalog_uri' => 'spotify:track:removed',
            'decision' => ExportReviewDecision::Remove,
        ]);
        ExportReviewItem::factory()->for($review)->create([
            'position' => 0,
            'match_status' => ExportMatchStatus::Matched,
            'target_catalog_id' => 'kept-target',
            'target_catalog_uri' => 'spotify:track:kept',
            'decision' => ExportReviewDecision::Keep,
        ]);

        $manifest = ConfirmedExportManifest::fromConfirmedReview($review);

        $this->assertSame($review->id, $manifest->reviewId);
        $this->assertSame($review->playlist_id, $manifest->playlistId);
        $this->assertSame($review->target_provider, $manifest->targetProvider);
        $this->assertSame($review->destination_type, $manifest->destinationType);
        $this->assertSame($review->target_account_id, $manifest->targetAccountId);
        $this->assertSame($review->target_market, $manifest->targetMarket);
        $this->assertSame(str_repeat('a', 64), $manifest->sourceFingerprint);
        $this->assertSame([[
            'position' => 0,
            'catalog_id' => 'kept-target',
            'catalog_uri' => 'spotify:track:kept',
        ]], $manifest->items);
        $this->assertTrue((new ReflectionClass(ConfirmedExportManifest::class))->isReadOnly());
        $this->assertFalse(method_exists($manifest, 'search'));
        $this->assertFalse(method_exists($manifest, 'rematch'));
        Http::assertNothingSent();
    }

    public function test_unconfirmed_review_cannot_be_read_as_a_manifest(): void
    {
        $review = ExportReview::factory()->create([
            'status' => ExportReviewStatus::Ready,
            'confirmed_at' => null,
        ]);

        $this->expectException(LogicException::class);
        ConfirmedExportManifest::fromConfirmedReview($review);
    }
}
