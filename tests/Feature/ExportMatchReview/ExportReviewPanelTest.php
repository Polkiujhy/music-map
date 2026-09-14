<?php

namespace Tests\Feature\ExportMatchReview;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Livewire\ExportReviewPanel;
use App\Models\ExportReview;
use App\Models\ExportReviewItem;
use App\Models\Playlist;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ExportReviewPanelTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_polling_reports_time_thresholds_announces_state_changes_and_stops_when_terminal(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:31');
        $review = $this->review(ExportReviewStatus::Queued, now()->subSeconds(31));

        $component = Livewire::actingAs($review->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->assertSeeHtml('wire:poll.3s="poll"')
            ->assertSee('Przetwarzanie nadal trwa');

        Carbon::setTestNow('2026-09-14 12:01:01');
        $component->call('poll')->assertSee('Możesz bezpiecznie opuścić tę stronę');

        $review->update(['status' => ExportReviewStatus::Ready, 'completed_at' => now()]);
        $component->call('poll')
            ->assertSet('status', ExportReviewStatus::Ready->value)
            ->assertSet('statusAnnouncement', 'Przegląd jest gotowy do podjęcia decyzji.')
            ->assertDontSeeHtml('wire:poll.3s="poll"');

        $component->call('poll')->assertSet('statusAnnouncement', 'Przegląd jest gotowy do podjęcia decyzji.');
    }

    public function test_three_match_classes_and_duplicate_occurrences_keep_independent_decisions(): void
    {
        $review = $this->review();
        $matched = $this->reviewItem($review, 0, ExportMatchStatus::Matched, 'Duplicate title');
        $firstDuplicate = $this->reviewItem($review, 1, ExportMatchStatus::Suspicious, 'Duplicate title');
        $secondDuplicate = $this->reviewItem($review, 2, ExportMatchStatus::Suspicious, 'Duplicate title');
        $unavailable = $this->reviewItem($review, 3, ExportMatchStatus::Unavailable, 'Unavailable title');
        $this->reviewItem($review, 4, ExportMatchStatus::Unavailable, 'Source unavailable', false);
        $this->reviewItem($review, 5, ExportMatchStatus::Unavailable, null);

        Livewire::actingAs($review->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->assertSet("decisions.{$matched->id}", ExportReviewDecision::Keep->value)
            ->assertSee('Pewne dopasowanie')
            ->assertSee('Wymaga decyzji')
            ->assertSee('Niedostępne w celu')
            ->assertSee('Źródło niedostępne')
            ->assertSee('Brak danych źródłowych')
            ->assertSee('Pozycja źródłowa jest niedostępna i nie została wyszukana w katalogu celu.')
            ->assertSee('Brak tytułu potrzebnego do wyszukania tej pozycji w katalogu celu.')
            ->assertSee('Nie znaleziono wiarygodnego odpowiednika w katalogu celu.')
            ->assertSee('Poza eksportem')
            ->assertSeeHtml('wire:key="review-item-'.$firstDuplicate->id.'"')
            ->assertSeeHtml('wire:key="review-item-'.$secondDuplicate->id.'"')
            ->call('choose', $firstDuplicate->id, 'keep')
            ->call('choose', $secondDuplicate->id, 'remove')
            ->call('choose', $unavailable->id, 'keep')
            ->assertSet("decisions.{$firstDuplicate->id}", 'keep')
            ->assertSet("decisions.{$secondDuplicate->id}", 'remove')
            ->assertSet("decisions.{$unavailable->id}", 'keep');

        $this->assertSame(ExportReviewDecision::Keep, $firstDuplicate->fresh()->decision);
        $this->assertSame(ExportReviewDecision::Remove, $secondDuplicate->fresh()->decision);
        $this->assertSame(ExportReviewDecision::Keep, $unavailable->fresh()->decision);
    }

    public function test_keep_all_persists_every_problematic_decision_and_clears_validation_errors(): void
    {
        $review = $this->review();
        $this->reviewItem($review, 0, ExportMatchStatus::Matched);

        $problematic = collect(range(1, 19))->map(fn (int $position): ExportReviewItem => $this->reviewItem(
            $review,
            $position,
            $position % 2 === 0 ? ExportMatchStatus::Suspicious : ExportMatchStatus::Unavailable,
        ));

        $component = Livewire::actingAs($review->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->call('confirm')
            ->assertHasErrors()
            ->call('keepAll')
            ->assertHasNoErrors();

        foreach ($problematic as $item) {
            $component->assertSet("decisions.{$item->id}", ExportReviewDecision::Keep->value);
            $this->assertSame(ExportReviewDecision::Keep, $item->fresh()->decision);
        }
    }

    public function test_confirmation_requires_a_decision_for_every_problematic_occurrence(): void
    {
        $review = $this->review();
        $this->reviewItem($review, 0, ExportMatchStatus::Matched);
        $first = $this->reviewItem($review, 1, ExportMatchStatus::Suspicious);
        $second = $this->reviewItem($review, 2, ExportMatchStatus::Unavailable);

        Livewire::actingAs($review->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->call('choose', $first->id, 'keep')
            ->call('confirm')
            ->assertHasErrors("decisions.{$second->id}");

        $this->assertSame(ExportReviewStatus::Ready, $review->fresh()->status);
    }

    public function test_failed_review_uses_the_throttled_http_retry_route(): void
    {
        $review = $this->review(ExportReviewStatus::Failed);

        Livewire::actingAs($review->user)
            ->test(ExportReviewPanel::class, ['exportReview' => $review])
            ->assertSee('Przygotuj nowy wynik')
            ->assertSeeHtml('method="POST"')
            ->assertSeeHtml('action="'.route('export-reviews.retry', [$review->playlist_id, $review->getKey()]).'"');
    }

    private function review(
        ExportReviewStatus $status = ExportReviewStatus::Ready,
        ?Carbon $startedAt = null,
    ): ExportReview {
        $playlist = Playlist::factory()->create();
        PlaylistItem::factory()->for($playlist)->create(['position' => 0]);
        $playlist->load(['user', 'items']);

        return ExportReview::factory()->for($playlist->user)->for($playlist)->create([
            'status' => $status,
            'target_account_id' => 'managed-spotify',
            'started_at' => $startedAt ?? now(),
            'source_fingerprint' => app(FingerprintPlaylistContent::class)->handle($playlist),
        ]);
    }

    private function reviewItem(
        ExportReview $review,
        int $position,
        ExportMatchStatus $status,
        ?string $title = 'Canary title',
        bool $sourceAvailable = true,
    ): ExportReviewItem {
        return ExportReviewItem::factory()->for($review)->create([
            'position' => $position,
            'source_title' => $title,
            'source_is_available' => $sourceAvailable,
            'source_occurrence_id' => 'occurrence-'.$position,
            'match_status' => $status,
            'target_catalog_id' => $status === ExportMatchStatus::Unavailable ? null : 'target-'.$position,
            'target_catalog_uri' => $status === ExportMatchStatus::Unavailable ? null : 'spotify:track:'.$position,
            'target_title' => $status === ExportMatchStatus::Unavailable ? null : 'Target '.$position,
            'target_creators' => $status === ExportMatchStatus::Unavailable ? null : ['Target artist'],
        ]);
    }
}
