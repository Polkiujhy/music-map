<?php

namespace App\Livewire;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Models\ExportReview;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $validated = validator(
            ['decision' => $decision],
            ['decision' => ['required', Rule::enum(ExportReviewDecision::class)]],
        )->validate();

        DB::transaction(function () use ($itemId, $validated): void {
            $review = Auth::user()?->exportReviews()
                ->whereKey($this->reviewId)
                ->lockForUpdate()
                ->firstOrFail() ?? abort(404);

            if ($review->status !== ExportReviewStatus::Ready) {
                $this->addError('review', 'Decyzje można zmieniać dopiero dla gotowego przeglądu.');

                return;
            }

            $item = $review->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if ($item->match_status === ExportMatchStatus::Matched && $validated['decision'] !== ExportReviewDecision::Keep->value) {
                $this->addError("decisions.{$itemId}", 'Pewne dopasowanie pozostaje w eksporcie.');

                return;
            }

            $item->forceFill(['decision' => $validated['decision']])->save();
            $this->decisions[$itemId] = $validated['decision'];
            $this->resetValidation("decisions.{$itemId}");
        });
    }

    public function keepAll(): void
    {
        DB::transaction(function (): void {
            $review = Auth::user()?->exportReviews()
                ->whereKey($this->reviewId)
                ->lockForUpdate()
                ->firstOrFail() ?? abort(404);

            if ($review->status !== ExportReviewStatus::Ready) {
                $this->addError('review', 'Decyzje można zmieniać dopiero dla gotowego przeglądu.');

                return;
            }

            foreach ($review->items()->lockForUpdate()->get() as $item) {
                if ($item->match_status !== ExportMatchStatus::Matched) {
                    $item->forceFill(['decision' => ExportReviewDecision::Keep])->save();
                }

                $this->decisions[(int) $item->getKey()] = ExportReviewDecision::Keep->value;
            }

            $this->resetValidation();
        });
    }

    public function confirm(ConfirmExportReview $confirm): void
    {
        $review = $this->ownedReview($this->reviewId);
        $operationId = $confirm->handle(Auth::user(), $review, $this->decisions);
        session()->flash('status', "Eksport został zakolejkowany (operacja {$operationId}).");
        $this->redirectRoute('export-operations.show', [$review->playlist_id, $operationId], navigate: false);
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
