<?php

namespace App\Http\Controllers;

use App\Actions\Exports\AbandonExportRecovery;
use App\Actions\Exports\RetryExportOperation;
use App\Http\Requests\AbandonExportRecoveryRequest;
use App\Http\Requests\RetryExportOperationRequest;
use App\Models\ExportOperation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ExportOperationController extends Controller
{
    public function show(Request $request, string $playlist, string $exportOperation): View
    {
        return view('exports.show', [
            'exportOperation' => $this->operation($request, $playlist, $exportOperation),
        ]);
    }

    public function retry(
        RetryExportOperationRequest $request,
        string $playlist,
        string $exportOperation,
        RetryExportOperation $retry,
    ): RedirectResponse {
        $operation = $retry->handle($request->user(), $request->ownedOperation());

        return to_route('export-operations.show', [$playlist, $operation->operation_id])
            ->with('status', 'Eksport został ponownie zakolejkowany.');
    }

    public function abandonRecovery(
        AbandonExportRecoveryRequest $request,
        string $playlist,
        string $exportOperation,
        AbandonExportRecovery $abandon,
    ): RedirectResponse {
        $operation = $abandon->handle(
            $request->user(),
            $request->ownedOperation(),
            $request->boolean('orphan_copies_checked'),
        );

        return to_route('export-operations.show', [$playlist, $operation->operation_id])
            ->with('status', 'Recovery zostało porzucone. Możesz przygotować nowy przegląd.');
    }

    private function operation(Request $request, string $playlist, string $exportOperation): ExportOperation
    {
        $source = $request->user()->playlists()->sourceOnly()->whereKey($playlist)->firstOrFail();

        return $request->user()->exportOperations()
            ->where('source_playlist_id', $source->getKey())
            ->where('operation_id', $exportOperation)
            ->firstOrFail();
    }
}
