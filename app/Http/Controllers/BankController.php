<?php

namespace App\Http\Controllers;

use App\Enums\ExportDestinationType;
use App\Enums\ExportOperationStatus;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportOperation;
use App\Models\ExportReview;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()
            ->with('managedExportTarget.sourcePlaylist')
            ->withCount([
                'items',
                'items as unavailable_items_count' => fn ($query) => $query->where('is_available', false),
            ])
            ->latest('imported_at')
            ->get();

        $accounts = $request->user()->streamingAccounts()->get()->keyBy(
            fn ($account) => $account->provider->value,
        );

        $reviews = (new ExportReview)->newCollection();

        foreach ([StreamingProvider::Spotify, StreamingProvider::YouTube] as $provider) {
            [$type, $accountId] = $this->currentDestination($provider, $accounts);

            if ($accountId === '') {
                continue;
            }

            $reviews = $reviews
                ->merge($this->latestReviews($request->user(), $provider, $type, $accountId, retryable: false))
                ->merge($this->latestReviews($request->user(), $provider, $type, $accountId, retryable: true));
        }

        foreach ($playlists as $playlist) {
            $playlist->setRelation(
                'exportReviews',
                $reviews->where('playlist_id', $playlist->getKey())->sortByDesc('id')->values(),
            );
        }

        $sourceIds = $playlists->reject(fn ($playlist) => $playlist->isManagedTarget())->modelKeys();
        $operationIds = collect()
            ->merge($this->latestOperationIds($request->user(), $sourceIds, [
                ExportOperationStatus::Queued,
                ExportOperationStatus::Processing,
                ExportOperationStatus::PartialFailed,
                ExportOperationStatus::ManualRecoveryRequired,
                ExportOperationStatus::RecreateRequired,
            ]))
            ->merge($this->latestOperationIds($request->user(), $sourceIds, [
                ExportOperationStatus::Succeeded,
                ExportOperationStatus::Failed,
            ]))
            ->unique()
            ->values();
        $operations = ExportOperation::query()
            ->whereIn('id', $operationIds)
            ->with([
                'exportReview',
                'playlistExport.targetAttempts',
                'playlistExport.targetPlaylist',
            ])
            ->get();

        foreach ($playlists as $playlist) {
            $playlist->setRelation(
                'managedOperations',
                $operations->filter(fn (ExportOperation $operation): bool => (int) $operation->playlistExport->source_playlist_id === (int) $playlist->getKey())
                    ->sortByDesc('created_at')
                    ->values(),
            );
        }

        return view('bank.index', ['playlists' => $playlists, 'accounts' => $accounts]);
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  list<ExportOperationStatus>  $statuses
     * @return Collection<int, string>
     */
    private function latestOperationIds(User $user, array $sourceIds, array $statuses): Collection
    {
        if ($sourceIds === []) {
            return collect();
        }

        $ranked = ExportOperation::query()
            ->join('playlist_exports', 'playlist_exports.id', '=', 'export_operations.playlist_export_id')
            ->where('export_operations.user_id', $user->getKey())
            ->whereIn('playlist_exports.source_playlist_id', $sourceIds)
            ->where('playlist_exports.destination_type', ExportDestinationType::Managed->value)
            ->whereIn('export_operations.status', array_map(fn (ExportOperationStatus $status): string => $status->value, $statuses))
            ->selectRaw('export_operations.id, ROW_NUMBER() OVER (PARTITION BY playlist_exports.source_playlist_id, playlist_exports.target_provider ORDER BY export_operations.created_at DESC, export_operations.id DESC) AS operation_rank');

        return collect(DB::query()->fromSub($ranked, 'ranked_operations')
            ->where('operation_rank', 1)
            ->pluck('id'));
    }

    /** @return array{ExportDestinationType, string} */
    private function currentDestination(StreamingProvider $provider, Collection $accounts): array
    {
        $account = $accounts->get($provider->value);
        $linked = $account instanceof StreamingAccount
            && $account->connectionState() === StreamingAccount::STATE_CONNECTED;

        return [
            $linked ? ExportDestinationType::Linked : ExportDestinationType::Managed,
            $linked
                ? $account->provider_account_id
                : (string) config("services.platform_access.{$provider->value}.technical.account_id"),
        ];
    }

    /** @return EloquentCollection<int, ExportReview> */
    private function latestReviews(
        User $user,
        StreamingProvider $provider,
        ExportDestinationType $type,
        string $accountId,
        bool $retryable,
    ): EloquentCollection {
        $latestIds = ExportReview::query()
            ->selectRaw('MAX(id)')
            ->where('user_id', $user->getKey())
            ->where('target_provider', $provider->value)
            ->where('destination_type', $type->value)
            ->where('target_account_id', $accountId)
            ->groupBy('playlist_id');

        if ($retryable) {
            $latestIds->where(function ($query): void {
                $query
                    ->whereIn('status', [ExportReviewStatus::Failed->value, ExportReviewStatus::Expired->value])
                    ->orWhere('expires_at', '<=', now());
            });
        } else {
            $latestIds
                ->whereIn('status', [
                    ExportReviewStatus::Queued->value,
                    ExportReviewStatus::Processing->value,
                    ExportReviewStatus::Ready->value,
                ])
                ->where('expires_at', '>', now());
        }

        return ExportReview::query()->whereIn('id', $latestIds)->get();
    }
}
