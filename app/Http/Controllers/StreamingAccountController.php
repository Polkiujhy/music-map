<?php

namespace App\Http\Controllers;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Actions\DisconnectStreamingAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StreamingAccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('integrations.index', [
            'accounts' => $request->user()->streamingAccounts()->limit(2)->get()->keyBy(
                fn ($account) => $account->provider->value,
            ),
        ]);
    }

    public function destroy(
        Request $request,
        string $streamingAccount,
        DisconnectStreamingAccount $disconnect,
    ): RedirectResponse {
        $request->validate([
            'confirm_disconnect' => ['required', 'accepted'],
        ]);

        $account = $request->user()
            ->streamingAccounts()
            ->whereKey($streamingAccount)
            ->firstOrFail();
        $provider = $account->provider;
        $revoked = $disconnect->handle($request->user(), $account);

        return to_route('integrations.index')->with(
            'status',
            $this->disconnectMessage($provider, $revoked),
        );
    }

    private function disconnectMessage(StreamingProvider $provider, bool $revoked): string
    {
        if ($provider === StreamingProvider::Spotify) {
            return 'Konto Spotify zostało odłączone lokalnie. Aby cofnąć dostęp u Spotify, użyj opcji Remove Access w ustawieniach aplikacji Spotify.';
        }

        if ($revoked) {
            return 'Konto YouTube zostało odłączone lokalnie, a dostęp u Google został cofnięty.';
        }

        return 'Konto YouTube zostało odłączone lokalnie. Nie udało się potwierdzić cofnięcia dostępu u Google; możesz usunąć dostęp ręcznie w ustawieniach konta Google.';
    }
}
