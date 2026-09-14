<?php

namespace App\Actions\ExportReviews;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\ExportMatchStatus;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\PlaylistOrigin;
use App\Jobs\PrepareExportReview;
use App\Models\ExportReview;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class StartExportReview
{
    public function __construct(private FingerprintPlaylistContent $fingerprint) {}

    public function handle(User $user, Playlist $playlist, ResolvedExportDestination $destination): ExportReview
    {
        if ((int) $playlist->user_id !== (int) $user->getKey()) {
            throw ValidationException::withMessages(['playlist' => 'Ta playlista nie należy do użytkownika.']);
        }

        return DB::transaction(function () use ($user, $playlist, $destination): ExportReview {
            $lockedPlaylist = Playlist::query()->whereKey($playlist->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedPlaylist->origin === PlaylistOrigin::ManagedTarget) {
                throw ValidationException::withMessages([
                    'playlist' => 'Zarządzana playlista docelowa jest tylko do odczytu.',
                ]);
            }

            $blockedByOperation = $lockedPlaylist->managedExports()
                ->where('target_provider', $destination->provider->value)
                ->where('target_account_id', $destination->accountId)
                ->whereHas('operations', fn ($query) => $query->whereIn('status', [
                    ExportOperationStatus::Queued->value,
                    ExportOperationStatus::Processing->value,
                    ExportOperationStatus::PartialFailed->value,
                    ExportOperationStatus::ManualRecoveryRequired->value,
                    ExportOperationStatus::RecreateRequired->value,
                ]))
                ->exists();

            if ($blockedByOperation) {
                throw ValidationException::withMessages([
                    'review' => 'Ten cel ma już aktywną lub niedokończoną operację eksportu.',
                ]);
            }

            $items = $lockedPlaylist->items()->get();

            if ($items->count() > 20) {
                throw ValidationException::withMessages(['playlist' => 'Playlista może zawierać maksymalnie 20 pozycji.']);
            }

            $lockedPlaylist->setRelation('items', $items);
            $fingerprint = $this->fingerprint->handle($lockedPlaylist);
            $active = ExportReview::query()
                ->where('playlist_id', $lockedPlaylist->getKey())
                ->where('target_provider', $destination->provider->value)
                ->where('destination_type', $destination->type->value)
                ->where('target_account_id', $destination->accountId)
                ->where('source_fingerprint', $fingerprint)
                ->whereIn('status', [
                    ExportReviewStatus::Queued->value,
                    ExportReviewStatus::Processing->value,
                    ExportReviewStatus::Ready->value,
                ])
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($active instanceof ExportReview) {
                return $active;
            }

            $review = new ExportReview([
                'target_provider' => $destination->provider,
                'destination_type' => $destination->type,
                'streaming_account_id' => $destination->streamingAccountId,
                'target_account_id' => $destination->accountId,
                'target_market' => $destination->market,
                'source_fingerprint' => $fingerprint,
                'status' => ExportReviewStatus::Queued,
                'failure_code' => null,
                'correlation_id' => (string) Str::uuid(),
                'started_at' => now(),
                'expires_at' => now()->addDay(),
            ]);
            $review->user()->associate($user);
            $review->playlist()->associate($lockedPlaylist);
            $review->save();

            foreach ($items as $item) {
                $review->items()->create([
                    'position' => $item->position,
                    'source_occurrence_id' => $item->occurrence_id,
                    'source_catalog_id' => $item->catalog_id,
                    'source_catalog_uri' => $item->catalog_uri,
                    'source_title' => $item->title,
                    'source_creators' => $item->creators,
                    'source_album' => $item->album,
                    'source_duration_milliseconds' => $item->duration_milliseconds,
                    'source_isrc' => $item->isrc,
                    'source_is_available' => $item->is_available,
                    'match_status' => ExportMatchStatus::Unavailable,
                ]);
            }

            PrepareExportReview::dispatch((int) $review->getKey())->afterCommit();

            return $review;
        });
    }
}
