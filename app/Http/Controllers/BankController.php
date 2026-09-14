<?php

namespace App\Http\Controllers;

use App\Enums\ExportReviewStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()
            ->with(['exportReviews' => fn ($query) => $query
                ->whereIn('status', [
                    ExportReviewStatus::Queued->value,
                    ExportReviewStatus::Processing->value,
                    ExportReviewStatus::Ready->value,
                    ExportReviewStatus::Failed->value,
                    ExportReviewStatus::Expired->value,
                ])
                ->latest('id')])
            ->withCount([
                'items',
                'items as unavailable_items_count' => fn ($query) => $query->where('is_available', false),
            ])
            ->latest('imported_at')
            ->get();

        $accounts = $request->user()->streamingAccounts()->get()->keyBy(
            fn ($account) => $account->provider->value,
        );

        return view('bank.index', ['playlists' => $playlists, 'accounts' => $accounts]);
    }
}
