<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()
            ->withCount([
                'items',
                'items as unavailable_items_count' => fn ($query) => $query->where('is_available', false),
            ])
            ->latest('imported_at')
            ->get();

        return view('bank.index', ['playlists' => $playlists]);
    }
}
