<?php

namespace App\Http\Controllers;

use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Actions\LinkStreamingAccount;
use App\Integrations\StreamingAccounts\Contracts\StreamingOAuthGateway;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingGrant;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Integrations\StreamingAccounts\StreamingOAuthAttemptStore;
use App\Integrations\StreamingAccounts\StreamingOAuthFailure;
use App\Models\StreamingAccount;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StreamingAccountOAuthController extends Controller
{
    public function __construct(
        private readonly Container $container,
        private readonly StreamingOAuthAttemptStore $attempts,
    ) {}

    public function connect(Request $request, string $provider): RedirectResponse
    {
        $streamingProvider = StreamingProvider::from($provider);
        $purpose = $request->user()->streamingAccounts()
            ->where('provider', $provider)
            ->exists() ? 'reconnect' : 'link';
        $state = $this->attempts->create(
            $request,
            $streamingProvider,
            $purpose,
            (int) $request->user()->getAuthIdentifier(),
        );

        return redirect()->away($this->gateway($streamingProvider)->authorizationUrl($state));
    }

    public function callback(
        Request $request,
        string $provider,
        LinkStreamingAccount $link,
    ): RedirectResponse {
        $streamingProvider = StreamingProvider::from($provider);
        $state = $request->query('state');
        $hasError = $request->filled('error');
        $code = $request->query('code');
        $userId = (int) $request->user()->getAuthIdentifier();

        $this->scrubCallbackQuery($request);

        if (! is_string($state) || ! $this->consumeAttempt(
            $request,
            $state,
            $streamingProvider,
            $userId,
        )) {
            return $this->failure('Sesja łączenia wygasła lub została już użyta. Spróbuj ponownie.');
        }

        if ($hasError) {
            return $this->failure('Łączenie konta zostało anulowane.');
        }

        if (! is_string($code) || $code === '') {
            return $this->failure('Nie udało się bezpiecznie połączyć konta. Spróbuj ponownie.');
        }

        try {
            $gateway = $this->gateway($streamingProvider);
            $grant = $gateway->exchange($code);

            if (! $grant instanceof StreamingGrant) {
                return $this->oauthFailure($grant);
            }

            if (array_diff($streamingProvider->requiredScopes(), $grant->scopes) !== []) {
                return $this->failure('Nie udzielono wszystkich wymaganych uprawnień. Połącz konto ponownie.');
            }

            $identity = $gateway->identity($grant->accessToken);

            if (! $identity instanceof StreamingIdentity) {
                return $this->oauthFailure($identity);
            }

            $result = $link->handle($request->user(), $streamingProvider, $grant, $identity);

            if ($result instanceof StreamingOAuthFailure) {
                return $this->oauthFailure($result);
            }

            return to_route('integrations.index')->with('status', 'Konto streamingowe zostało połączone.');
        } catch (Throwable $exception) {
            Log::warning('Streaming OAuth callback failed.', [
                'exception_class' => $exception::class,
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $this->failure('Nie udało się teraz połączyć konta. Spróbuj ponownie później.');
        }
    }

    public function verify(
        Request $request,
        string $streamingAccount,
        WithStreamingAccess $access,
    ): RedirectResponse {
        $account = $request->user()->streamingAccounts()->whereKey($streamingAccount)->first();

        if (! $account instanceof StreamingAccount) {
            return $this->failure('Nie udało się sprawdzić tego połączenia.');
        }

        $result = $access->handle(
            $request->user(),
            $account,
            $account->provider->requiredScopes(),
            static function (StreamingAccessContext $context): void {},
        );

        if ($result->successful) {
            return to_route('integrations.index')->with('status', 'Połączenie działa prawidłowo.');
        }

        return match ($result->failure) {
            StreamingAccessFailure::ReconnectRequired,
            StreamingAccessFailure::MissingScope => $this->failure('Połącz konto ponownie, aby przywrócić dostęp.'),
            default => $this->failure('Provider jest chwilowo niedostępny. Spróbuj ponownie później.'),
        };
    }

    private function consumeAttempt(
        Request $request,
        string $state,
        StreamingProvider $provider,
        int $userId,
    ): bool {
        foreach (['link', 'reconnect'] as $purpose) {
            if ($this->attempts->consume($request, $state, $provider, $purpose, $userId)) {
                return true;
            }
        }

        return false;
    }

    private function scrubCallbackQuery(Request $request): void
    {
        $request->query->replace([]);
        $request->server->set('QUERY_STRING', '');
    }

    private function gateway(StreamingProvider $provider): StreamingOAuthGateway
    {
        return $this->container->make('streaming-oauth.'.$provider->value);
    }

    private function oauthFailure(StreamingOAuthFailure $failure): RedirectResponse
    {
        return match ($failure) {
            StreamingOAuthFailure::MissingScope => $this->failure('Nie udzielono wszystkich wymaganych uprawnień. Połącz konto ponownie.'),
            StreamingOAuthFailure::AccountConflict => $this->failure('Najpierw odłącz obecne konto tego providera.'),
            StreamingOAuthFailure::AuthorizationDenied => $this->failure('Autoryzacja wygasła lub została odrzucona. Połącz konto ponownie.'),
            default => $this->failure('Nie udało się teraz połączyć konta. Spróbuj ponownie później.'),
        };
    }

    private function failure(string $message): RedirectResponse
    {
        return to_route('integrations.index')->with('error', $message);
    }
}
