<?php

namespace App\Http\Controllers;

use App\Enums\ExportDestinationType;
use App\Enums\ExportReviewStatus;
use App\Enums\StreamingProvider;
use App\Models\ExportReview;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()
            ->sourceOnly()
            ->withCount([
                'items',
                'items as unavailable_items_count' => fn ($query) => $query->where('is_available', false),
            ])
            ->with([
                'exportLinks' => fn ($query) => $query->latest('id'),
                'exportLinks.targetPlaylist',
                'exportLinks.latestOperation',
                'activeExportOperations',
            ])
            ->latest('imported_at')
            ->paginate(20);

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

        return view('bank.index', ['playlists' => $playlists, 'accounts' => $accounts]);
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
