<?php

namespace App\Livewire;

use App\Actions\Exports\AbandonExportRecovery;
use App\Actions\Exports\RetryExportOperation;
use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationFailure;
use App\Enums\ExportOperationStatus;
use App\Integrations\PlaylistExport\ProviderPlaylistUrl;
use App\Models\ExportOperation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ExportOperationPanel extends Component
{
    #[Locked]
    public string $operationId;

    #[Locked]
    public int $sourcePlaylistId;

    public string $status;

    public string $statusAnnouncement = '';

    public bool $orphanCopiesChecked = false;

    public function mount(ExportOperation $exportOperation): void
    {
        $operation = $this->ownedOperation($exportOperation->operation_id);
        $this->operationId = $operation->operation_id;
        $this->sourcePlaylistId = (int) $operation->source_playlist_id;
        $this->status = $operation->status->value;
    }

    public function poll(): void
    {
        if (! $this->isPolling) {
            return;
        }

        $current = $this->ownedOperation($this->operationId)->status->value;
        if ($current !== $this->status) {
            $this->status = $current;
            $this->statusAnnouncement = $this->statusLabel;
            unset($this->isPolling);
        }
    }

    public function retry(RetryExportOperation $retry): void
    {
        $operation = $retry->handle(Auth::user(), $this->ownedOperation($this->operationId));
        session()->flash('status', 'Eksport został ponownie zakolejkowany.');
        $this->redirectRoute(
            'export-operations.show',
            [$operation->source_playlist_id, $operation->operation_id],
            navigate: false,
        );
    }

    public function abandonRecovery(AbandonExportRecovery $abandon): void
    {
        $this->validate([
            'orphanCopiesChecked' => ['accepted'],
        ], [
            'orphanCopiesChecked.accepted' => 'Potwierdź sprawdzenie i usunięcie osieroconych kopii.',
        ]);

        $operation = $abandon->handle(
            Auth::user(),
            $this->ownedOperation($this->operationId),
            $this->orphanCopiesChecked,
        );
        session()->flash('status', 'Recovery zostało porzucone. Możesz przygotować nowy przegląd.');
        $this->redirectRoute(
            'export-operations.show',
            [$operation->source_playlist_id, $operation->operation_id],
            navigate: false,
        );
    }

    public function getIsPollingProperty(): bool
    {
        return in_array($this->status, [
            ExportOperationStatus::Queued->value,
            ExportOperationStatus::Processing->value,
        ], true);
    }

    public function getStatusLabelProperty(): string
    {
        $status = ExportOperationStatus::from($this->status);

        if ($status === ExportOperationStatus::Transferred) {
            return $this->operation()->destination_type === ExportDestinationType::Linked
                ? 'Przeniesiona — na Twoim koncie'
                : 'Przeniesiona — zarządzana przez music-map';
        }

        return match ($status) {
            ExportOperationStatus::Queued, ExportOperationStatus::Processing => 'W trakcie przenoszenia',
            ExportOperationStatus::Failed => 'Nie przeniesiono',
            ExportOperationStatus::Incomplete => 'Nie udało się dokończyć przenoszenia',
            ExportOperationStatus::Transferred => throw new \LogicException('Transferred status is handled above.'),
        };
    }

    public function getFailureReasonProperty(): ?string
    {
        return match ($this->operation()->failure_code) {
            null => null,
            ExportOperationFailure::AdmissionLimit => 'Dzienny limit eksportów do YouTube został wykorzystany. Spróbuj ponownie po odnowieniu limitu.',
            ExportOperationFailure::AdmissionUnavailable => 'Dopuszczenie zapisu do YouTube jest chwilowo niedostępne.',
            ExportOperationFailure::ReconnectRequired => 'Połącz ponownie konto platformy przed ponowieniem.',
            ExportOperationFailure::MissingScope => 'Połącz ponownie konto i zaakceptuj wymagane uprawnienia.',
            ExportOperationFailure::StaleCredential => 'Dostęp do konta zmienił się. Zweryfikuj połączenie i ponów eksport.',
            ExportOperationFailure::RateLimited => 'Platforma ograniczyła liczbę żądań. Spróbuj ponownie za kilka minut.',
            ExportOperationFailure::QuotaLimited => 'Platforma wykorzystała dostępny limit operacji. Spróbuj ponownie później.',
            ExportOperationFailure::TemporaryFailure => 'Platforma jest chwilowo niedostępna. Spróbuj ponownie za kilka minut.',
            ExportOperationFailure::AccessDenied => 'Platforma odmówiła dostępu do docelowej playlisty.',
            ExportOperationFailure::InvalidResponse => 'Platforma zwróciła odpowiedź, której nie można bezpiecznie zastosować.',
            ExportOperationFailure::TargetDeleted => 'Docelowa playlista została usunięta. Przygotuj i świadomie potwierdź nowy przegląd.',
            ExportOperationFailure::UnsupportedDuplicate => 'Ta platforma nie obsługuje powtórzeń występujących w zatwierdzonym eksporcie.',
            ExportOperationFailure::AmbiguousCreate => 'Nie można jednoznacznie ustalić, która kopia została utworzona. Najpierw sprawdź playlisty u providera.',
            ExportOperationFailure::RecoveryScanIncomplete => 'Nie udało się bezpiecznie przeskanować wszystkich playlist. Ponowienie wykona wyłącznie kolejny skan.',
            ExportOperationFailure::RecoveryAbandoned => 'Odzyskiwanie zostało świadomie porzucone. Możesz przygotować nowy przegląd.',
        };
    }

    public function getCanRetryProperty(): bool
    {
        $operation = $this->operation();

        return in_array($operation->status, [ExportOperationStatus::Failed, ExportOperationStatus::Incomplete], true)
            && $operation->active_key !== null
            && $operation->failure_code !== null
            && ! in_array($operation->failure_code, [
                ExportOperationFailure::TargetDeleted,
                ExportOperationFailure::RecoveryAbandoned,
            ], true);
    }

    public function getNeedsRecoveryDecisionProperty(): bool
    {
        return $this->operation()->failure_code === ExportOperationFailure::AmbiguousCreate;
    }

    public function getCanStartNewReviewProperty(): bool
    {
        return in_array($this->operation()->failure_code, [
            ExportOperationFailure::TargetDeleted,
            ExportOperationFailure::RecoveryAbandoned,
        ], true);
    }

    public function getTargetUrlProperty(): ?string
    {
        $operation = $this->operation();
        $providerId = $operation->playlistExportLink?->targetPlaylist?->source_playlist_id;

        return is_string($providerId)
            ? ProviderPlaylistUrl::fromId($operation->target_provider, $providerId)
            : null;
    }

    public function render()
    {
        return view('livewire.export-operation-panel', [
            'operation' => $this->operation(),
        ]);
    }

    private function operation(): ExportOperation
    {
        return $this->ownedOperation($this->operationId)->load([
            'sourcePlaylist',
            'playlistExportLink.targetPlaylist',
        ]);
    }

    private function ownedOperation(string $operationId): ExportOperation
    {
        return Auth::user()?->exportOperations()
            ->where('operation_id', $operationId)
            ->whereHas('sourcePlaylist', fn ($query) => $query->sourceOnly())
            ->firstOrFail() ?? abort(404);
    }
}
