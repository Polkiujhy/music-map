<?php

namespace App\Http\Controllers;

use App\Actions\ExportReviews\ConfirmExportReview;
use App\Actions\ExportReviews\ResolveExportDestination;
use App\Actions\ExportReviews\StartExportReview;
use App\Enums\ExportReviewStatus;
use App\Http\Requests\ConfirmExportReviewRequest;
use App\Http\Requests\StartExportReviewRequest;
use App\Models\ExportReview;
use App\Models\Playlist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PlaylistExportReviewController extends Controller
{
    public function store(
        StartExportReviewRequest $request,
        string $playlist,
        ResolveExportDestination $destinations,
        StartExportReview $start,
    ): RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $destination = $destinations->handle(
            $request->user(),
            $ownedPlaylist,
            $request->provider(),
            $request->destinationType(),
            $request->streamingAccountId(),
        );
        $review = $start->handle($request->user(), $ownedPlaylist, $destination);

        return to_route('export-reviews.show', [$ownedPlaylist, $review]);
    }

    public function show(Request $request, string $playlist, string $exportReview): View
    {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $review = $this->review($request, $ownedPlaylist, $exportReview);

        return view('export-reviews.show', ['exportReview' => $review]);
    }

    public function retry(
        Request $request,
        string $playlist,
        string $exportReview,
        ResolveExportDestination $destinations,
        StartExportReview $start,
    ): RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $review = $this->review($request, $ownedPlaylist, $exportReview);

        if (! in_array($review->status, [ExportReviewStatus::Failed, ExportReviewStatus::Expired], true)) {
            throw ValidationException::withMessages(['review' => 'Ponowić można wyłącznie zakończony niepowodzeniem lub wygasły przegląd.']);
        }

        $destination = $destinations->handle(
            $request->user(),
            $ownedPlaylist,
            $review->target_provider,
            $review->destination_type,
            $review->streaming_account_id === null ? null : (int) $review->streaming_account_id,
        );
        $replacement = $start->handle($request->user(), $ownedPlaylist, $destination);

        return to_route('export-reviews.show', [$ownedPlaylist, $replacement]);
    }

    public function confirm(
        ConfirmExportReviewRequest $request,
        string $playlist,
        string $exportReview,
        ConfirmExportReview $confirm,
    ): RedirectResponse {
        $ownedPlaylist = $this->playlist($request, $playlist);
        $review = $this->review($request, $ownedPlaylist, $exportReview);
        $operationId = $confirm->handle($request->user(), $review, $request->decisions());

        return to_route('export-reviews.show', [$ownedPlaylist, $review])
            ->with('status', "Eksport został zakolejkowany (operacja {$operationId}).");
    }

    private function playlist(Request $request, string $playlist): Playlist
    {
        return $request->user()->playlists()->sourceOnly()->whereKey($playlist)->firstOrFail();
    }

    private function review(Request $request, Playlist $playlist, string $exportReview): ExportReview
    {
        return $request->user()->exportReviews()
            ->where('playlist_id', $playlist->getKey())
            ->whereKey($exportReview)
            ->firstOrFail();
    }
}
