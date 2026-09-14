<?php

namespace Tests\Feature\ExportMatchReview;

use App\Enums\ExportDestinationType;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportReviewContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_enums_are_closed_to_the_review_contract(): void
    {
        $this->assertSame(['queued', 'processing', 'ready', 'failed', 'confirmed', 'expired'], array_column(ExportReviewStatus::cases(), 'value'));
        $this->assertSame(['linked', 'managed'], array_column(ExportDestinationType::cases(), 'value'));
        $this->assertSame(['matched', 'suspicious', 'unavailable'], array_column(ExportMatchStatus::cases(), 'value'));
        $this->assertSame(['keep', 'remove'], array_column(ExportReviewDecision::cases(), 'value'));
    }

    public function test_factories_preserve_ownership_relationships_order_and_casts(): void
    {
        $review = ExportReview::factory()->create();
        ExportReviewItem::factory()->for($review)->create(['position' => 1]);
        ExportReviewItem::factory()->for($review)->create(['position' => 0]);

        $this->assertSame($review->user_id, $review->playlist->user_id);
        $this->assertTrue($review->user->exportReviews->contains($review));
        $this->assertTrue($review->playlist->exportReviews->contains($review));
        $this->assertSame([0, 1], $review->items->pluck('position')->all());
        $this->assertSame(StreamingProvider::Spotify, $review->target_provider);
        $this->assertSame(ExportDestinationType::Managed, $review->destination_type);
        $this->assertSame(ExportReviewStatus::Queued, $review->status);
        $this->assertInstanceOf(CarbonImmutable::class, $review->expires_at);
        $this->assertSame(ExportMatchStatus::Matched, $review->items->first()->match_status);
        $this->assertIsArray($review->items->first()->source_creators);
    }

    public function test_owner_or_playlist_delete_cascades_and_account_delete_preserves_identity(): void
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create();
        $review = ExportReview::factory()->for($user)->for($playlist)->create([
            'streaming_account_id' => $account->id,
            'destination_type' => ExportDestinationType::Linked,
            'target_account_id' => $account->provider_account_id,
        ]);
        ExportReviewItem::factory()->for($review)->create();

        $account->delete();
        $review->refresh();
        $this->assertNull($review->streaming_account_id);
        $this->assertNotNull($review->target_account_id);

        $playlist->delete();
        $this->assertDatabaseMissing('export_reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('export_review_items', ['export_review_id' => $review->id]);
    }
}
