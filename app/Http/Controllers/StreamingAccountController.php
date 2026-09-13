<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class StreamingAccountController extends Controller
{
    public function index(): View
    {
        return view('integrations.index', [
            'accounts' => request()->user()->streamingAccounts()->get()->keyBy(
                fn ($account) => $account->provider->value,
            ),
        ]);
    }
}
