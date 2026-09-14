<?php

namespace App\Http\Controllers;

use App\Actions\ManagedAccountExport\RequestManagedTargetRecreation;
use App\Actions\ManagedAccountExport\RetryManagedExport;
use App\Models\ExportOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ManagedExportController extends Controller
{
    public function retry(
        Request $request,
        string $playlist,
        string $exportOperation,
        RetryManagedExport $retry,
    ): RedirectResponse {
        $operation = $this->operation($request, $playlist, $exportOperation);
        $retry->handle($request->user(), $operation);

        return to_route('export-reviews.show', [$playlist, $operation->export_review_id])
            ->with('status', 'Ponowienie zostało przyjęte. Zaktualizujemy tę samą playlistę.');
    }

    public function recreate(
        Request $request,
        string $playlist,
        string $exportOperation,
        RequestManagedTargetRecreation $recreate,
    ): RedirectResponse {
        $request->validate([
            'confirm_recreation' => ['accepted'],
        ], [
            'confirm_recreation.accepted' => 'Potwierdź, że odtworzenie może utworzyć nowy link.',
        ]);

        $operation = $this->operation($request, $playlist, $exportOperation);
        $recreate->handle($request->user(), $operation);

        return to_route('export-reviews.show', [$playlist, $operation->export_review_id])
            ->with('status', 'Odtworzenie celu zostało przyjęte. Nowa kopia może mieć inny link.');
    }

    private function operation(Request $request, string $playlist, string $operation): ExportOperation
    {
        return $request->user()->exportOperations()
            ->whereKey($operation)
            ->whereHas('playlistExport', fn ($query) => $query->where('source_playlist_id', $playlist))
            ->with('playlistExport')
            ->firstOrFail();
    }
}
