<?php

namespace App\Livewire;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\ExportReviews\ResolveExportDestination;
use App\Actions\ExportReviews\StartExportReview;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Models\ExportReview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ExportReviewPanel extends Component
{
    #[Locked]
    public int $reviewId;

    public string $status;

    public int $startedAt;

    public string $statusAnnouncement = '';

    /** @var array<int, string> */
    public array $decisions = [];

    public function mount(ExportReview $exportReview): void
    {
        $review = $this->ownedReview((int) $exportReview->getKey())->load('items');
        $this->reviewId = (int) $review->getKey();
        $this->status = $review->status->value;
        $this->startedAt = ($review->started_at ?? $review->created_at)->getTimestamp();

        foreach ($review->items as $item) {
            if ($item->match_status === ExportMatchStatus::Matched) {
                $this->decisions[(int) $item->getKey()] = ExportReviewDecision::Keep->value;
            } elseif ($item->decision instanceof ExportReviewDecision) {
                $this->decisions[(int) $item->getKey()] = $item->decision->value;
            }
        }
    }

    public function poll(): void
    {
        if (! $this->isPolling) {
            return;
        }

        $current = $this->ownedReview($this->reviewId)->status->value;
        if ($current !== $this->status) {
            $this->status = $current;
            $this->statusAnnouncement = $this->statusLabel;
            unset($this->isPolling);
        }
    }

    public function choose(int $itemId, string $decision): void
    {
        $this->validate([
            "decisions.{$itemId}" => ['nullable'],
        ]);

        $review = $this->ownedReview($this->reviewId);
        if ($review->status !== ExportReviewStatus::Ready) {
            $this->addError('review', 'Decyzje można zmieniać dopiero dla gotowego przeglądu.');

            return;
        }

        $validated = validator(
            ['decision' => $decision],
            ['decision' => ['required', Rule::enum(ExportReviewDecision::class)]],
        )->validate();
        $item = $review->items()->whereKey($itemId)->firstOrFail();

        if ($item->match_status === ExportMatchStatus::Matched && $validated['decision'] !== ExportReviewDecision::Keep->value) {
            $this->addError("decisions.{$itemId}", 'Pewne dopasowanie pozostaje w eksporcie.');

            return;
        }

        $item->forceFill(['decision' => $validated['decision']])->save();
        $this->decisions[$itemId] = $validated['decision'];
    }

    public function confirm(ConfirmExportReview $confirm): void
    {
        $review = $this->ownedReview($this->reviewId);
        $confirm->handle(Auth::user(), $review, $this->decisions);
        session()->flash('status', 'Przegląd został potwierdzony. Dokładny manifest jest gotowy do eksportu.');
        $this->redirectRoute('export-reviews.show', [$review->playlist_id, $review->getKey()], navigate: false);
    }

    public function retry(ResolveExportDestination $destinations, StartExportReview $start): void
    {
        $review = $this->ownedReview($this->reviewId);
        if (! in_array($review->status, [ExportReviewStatus::Failed, ExportReviewStatus::Expired], true)) {
            $this->addError('review', 'Ten przegląd nie może zostać ponowiony.');

            return;
        }

        $playlist = Auth::user()->playlists()->whereKey($review->playlist_id)->firstOrFail();
        $destination = $destinations->handle(
            Auth::user(),
            $playlist,
            $review->target_provider,
            $review->destination_type,
            $review->streaming_account_id === null ? null : (int) $review->streaming_account_id,
        );
        $replacement = $start->handle(Auth::user(), $playlist, $destination);

        $this->redirectRoute('export-reviews.show', [$playlist->getKey(), $replacement->getKey()], navigate: false);
    }

    public function getIsPollingProperty(): bool
    {
        return in_array($this->status, [ExportReviewStatus::Queued->value, ExportReviewStatus::Processing->value], true);
    }

    public function getElapsedSecondsProperty(): int
    {
        return max(0, now()->getTimestamp() - $this->startedAt);
    }

    public function getStatusLabelProperty(): string
    {
        return match (ExportReviewStatus::from($this->status)) {
            ExportReviewStatus::Queued => 'Przegląd czeka na rozpoczęcie.',
            ExportReviewStatus::Processing => 'Trwa wyszukiwanie odpowiedników.',
            ExportReviewStatus::Ready => 'Przegląd jest gotowy do podjęcia decyzji.',
            ExportReviewStatus::Failed => 'Nie udało się przygotować przeglądu.',
            ExportReviewStatus::Confirmed => 'Przegląd został potwierdzony.',
            ExportReviewStatus::Expired => 'Przegląd wygasł.',
        };
    }

    public function render()
    {
        $review = $this->ownedReview($this->reviewId)->load(['items', 'playlist']);

        return view('livewire.export-review-panel', ['review' => $review]);
    }

    private function ownedReview(int $id): ExportReview
    {
        return Auth::user()?->exportReviews()->whereKey($id)->firstOrFail() ?? abort(404);
    }
}
