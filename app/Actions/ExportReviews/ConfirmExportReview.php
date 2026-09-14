<?php

namespace App\Actions\ExportReviews;

use App\Enums\ExportMatchStatus;
use App\Enums\ExportReviewDecision;
use App\Enums\ExportReviewStatus;
use App\Integrations\ExportMatching\Data\ConfirmedExportManifest;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ConfirmExportReview
{
    public function __construct(
        private FingerprintPlaylist $fingerprint,
        private ResolveExportDestination $destinations,
    ) {}

    /**
     * @param  array<int|string, string|ExportReviewDecision>  $decisions
     */
    public function handle(User $user, ExportReview $review, array $decisions): ConfirmedExportManifest
    {
        if ((int) $review->user_id !== (int) $user->getKey()) {
            abort(404);
        }

        return DB::transaction(function () use ($user, $review, $decisions): ConfirmedExportManifest {
            $locked = ExportReview::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->user_id !== (int) $user->getKey()) {
                abort(404);
            }

            if ($locked->status === ExportReviewStatus::Confirmed) {
                return ConfirmedExportManifest::fromConfirmedReview($locked->load('items'));
            }

            if ($locked->status !== ExportReviewStatus::Ready) {
                $this->invalid('review', 'Ten przegląd nie jest gotowy do potwierdzenia.');
            }

            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => ExportReviewStatus::Expired])->save();
                $this->invalid('review', 'Ten przegląd wygasł. Przygotuj nowy wynik.');
            }

            $playlist = Playlist::query()->whereKey($locked->playlist_id)->lockForUpdate()->firstOrFail();
            if ((int) $playlist->user_id !== (int) $user->getKey()) {
                abort(404);
            }

            $playlist->load('items');
            if (! hash_equals($locked->source_fingerprint, $this->fingerprint->handle($playlist))) {
                $this->invalid('review', 'Zawartość playlisty zmieniła się. Przygotuj nowy przegląd.');
            }

            $destination = $this->destinations->handle(
                $user,
                $playlist,
                $locked->target_provider,
                $locked->destination_type,
                $locked->streaming_account_id === null ? null : (int) $locked->streaming_account_id,
            );

            if ($destination->accountId !== $locked->target_account_id
                || $destination->market !== $locked->target_market
                || $destination->streamingAccountId !== ($locked->streaming_account_id === null ? null : (int) $locked->streaming_account_id)) {
                $this->invalid('destination', 'Wybrany cel zmienił się. Przygotuj nowy przegląd.');
            }

            $items = $locked->items()->lockForUpdate()->get();
            $normalized = [];

            foreach ($items as $item) {
                $decision = $item->match_status === ExportMatchStatus::Matched
                    ? ExportReviewDecision::Keep
                    : $this->decision($decisions[$item->getKey()] ?? null, (int) $item->getKey());
                $normalized[(int) $item->getKey()] = $decision;
            }

            $exported = $items->filter(fn ($item): bool => $item->match_status !== ExportMatchStatus::Unavailable
                && $normalized[(int) $item->getKey()] === ExportReviewDecision::Keep);

            if ($exported->isEmpty()) {
                $this->invalid('decisions', 'Eksport musi zawierać co najmniej jedną dostępną pozycję.');
            }

            foreach ($items as $item) {
                $item->forceFill(['decision' => $normalized[(int) $item->getKey()]])->save();
            }

            $keptSourceItems = $items
                ->reject(fn ($item): bool => $normalized[(int) $item->getKey()] === ExportReviewDecision::Remove)
                ->values();

            $playlist->items()->delete();
            $playlist->items()->createMany($keptSourceItems->map(fn ($item, int $position): array => [
                'position' => $position,
                'occurrence_id' => $item->source_occurrence_id,
                'catalog_id' => $item->source_catalog_id,
                'catalog_uri' => $item->source_catalog_uri,
                'title' => $item->source_title,
                'creators' => $item->source_creators,
                'album' => $item->source_album,
                'duration_milliseconds' => $item->source_duration_milliseconds,
                'isrc' => $item->source_isrc,
                'is_available' => $item->source_is_available,
            ])->all());

            $locked->forceFill([
                'status' => ExportReviewStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            return ConfirmedExportManifest::fromConfirmedReview($locked->load('items'));
        });
    }

    private function decision(mixed $value, int $itemId): ExportReviewDecision
    {
        $decision = $value instanceof ExportReviewDecision
            ? $value
            : (is_string($value) ? ExportReviewDecision::tryFrom($value) : null);

        if (! $decision instanceof ExportReviewDecision) {
            $this->invalid("decisions.{$itemId}", 'Wybierz zachowaj albo usuń dla każdej problematycznej pozycji.');
        }

        return $decision;
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
