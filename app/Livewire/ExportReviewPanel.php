<?php

namespace App\Livewire;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Integrations\ManagedAccountExport\ManagedExportFailureCode;
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

    public ?string $operationStatus = null;

    public int $startedAt;

    public string $statusAnnouncement = '';

    /** @var array<int, string> */
    public array $decisions = [];

    public function mount(ExportReview $exportReview): void
    {
        $review = $this->ownedReview((int) $exportReview->getKey())->load(['items', 'exportOperation']);
        $this->reviewId = (int) $review->getKey();
        $this->status = $review->status->value;
        $this->startedAt = ($review->started_at ?? $review->created_at)->getTimestamp();
        $this->operationStatus = $review->exportOperation?->status->value;
        if ($review->exportOperation !== null) {
            $this->startedAt = ($review->exportOperation->started_at ?? $review->exportOperation->created_at)->getTimestamp();
        }

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

        $review = $this->ownedReview($this->reviewId)->load('exportOperation');
        $current = $review->status->value;
        $operation = $review->exportOperation?->status->value;
        if ($current !== $this->status || $operation !== $this->operationStatus) {
            $this->status = $current;
            $this->operationStatus = $operation;
            unset($this->isPolling);
            $this->statusAnnouncement = $this->statusLabel;
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
        $confirm->handle(Auth::user(), $review, $this->decisions);
        session()->flash('status', 'Przegląd został potwierdzony. Eksport został rozpoczęty.');
        $this->redirectRoute('export-reviews.show', [$review->playlist_id, $review->getKey()], navigate: false);
    }

    public function getIsPollingProperty(): bool
    {
        if ($this->operationStatus !== null) {
            return in_array($this->operationStatus, [
                ExportOperationStatus::Queued->value,
                ExportOperationStatus::Processing->value,
            ], true);
        }

        return in_array($this->status, [ExportReviewStatus::Queued->value, ExportReviewStatus::Processing->value], true);
    }

    public function getElapsedSecondsProperty(): int
    {
        return max(0, now()->getTimestamp() - $this->startedAt);
    }

    public function getStatusLabelProperty(): string
    {
        if ($this->operationStatus !== null) {
            return match (ExportOperationStatus::from($this->operationStatus)) {
                ExportOperationStatus::Queued,
                ExportOperationStatus::Processing => 'W trakcie przenoszenia',
                ExportOperationStatus::Succeeded => 'Przeniesiona — zarządzana przez music-map',
                ExportOperationStatus::Failed => 'Nie przeniesiono',
                ExportOperationStatus::PartialFailed,
                ExportOperationStatus::ManualRecoveryRequired,
                ExportOperationStatus::RecreateRequired => 'Nie udało się dokończyć przenoszenia',
            };
        }

        return match (ExportReviewStatus::from($this->status)) {
            ExportReviewStatus::Queued => 'Przegląd czeka na rozpoczęcie.',
            ExportReviewStatus::Processing => 'Trwa wyszukiwanie odpowiedników.',
            ExportReviewStatus::Ready => 'Przegląd jest gotowy do podjęcia decyzji.',
            ExportReviewStatus::Failed => 'Nie udało się przygotować przeglądu.',
            ExportReviewStatus::Confirmed => 'Przegląd został potwierdzony.',
            ExportReviewStatus::Expired => 'Przegląd wygasł.',
        };
    }

    public function getFailureMessageProperty(): ?string
    {
        $failure = $this->ownedReview($this->reviewId)->exportOperation?->failure_code;

        return match ($failure) {
            ManagedExportFailureCode::RateLimited => 'Platforma ograniczyła liczbę żądań.',
            ManagedExportFailureCode::QuotaExceeded => 'Dzienny limit operacji YouTube został wykorzystany.',
            ManagedExportFailureCode::AuthenticationRequired,
            ManagedExportFailureCode::RequiredScopeMissing,
            ManagedExportFailureCode::RefreshRotationRequired => 'Dostęp techniczny wymaga ponownej konfiguracji.',
            ManagedExportFailureCode::TargetMissing => 'Nie znaleziono zapisanej playlisty docelowej.',
            ManagedExportFailureCode::AmbiguousMutation => 'Platforma nie potwierdziła jednoznacznie wyniku zapisu.',
            ManagedExportFailureCode::AccountMismatch,
            ManagedExportFailureCode::TargetOwnerMismatch => 'Nie można potwierdzić właściciela playlisty docelowej.',
            ManagedExportFailureCode::TargetMarkerMismatch,
            ManagedExportFailureCode::TargetVisibilityMismatch => 'Playlista docelowa nie spełnia warunków bezpiecznego ponowienia.',
            ManagedExportFailureCode::MetadataRejected,
            ManagedExportFailureCode::ItemRejected => 'Platforma odrzuciła część wymaganych zmian.',
            ManagedExportFailureCode::ConfigurationUnavailable,
            ManagedExportFailureCode::TransportUnavailable,
            ManagedExportFailureCode::InvalidResponse,
            ManagedExportFailureCode::PersistenceFailure => 'Wystąpił tymczasowy problem techniczny.',
            null => null,
        };
    }

    public function render()
    {
        $review = $this->ownedReview($this->reviewId)->load([
            'items',
            'playlist',
            'exportOperation.playlistExport.targetAttempts',
            'exportOperation.playlistExport.targetPlaylist',
        ]);

        return view('livewire.export-review-panel', ['review' => $review]);
    }

    private function ownedReview(int $id): ExportReview
    {
        return Auth::user()?->exportReviews()->whereKey($id)->firstOrFail() ?? abort(404);
    }
}
