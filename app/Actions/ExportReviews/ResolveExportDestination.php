<?php

namespace App\Actions\ExportReviews;

use App\Enums\ExportDestinationType;
use App\Enums\StreamingProvider;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\Data\StreamingIdentity;
use App\Integrations\StreamingAccounts\SpotifyOAuthGateway;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final readonly class ResolveExportDestination
{
    public function __construct(
        private WithStreamingAccess $access,
        private SpotifyOAuthGateway $spotify,
    ) {}

    public function handle(
        User $user,
        Playlist $playlist,
        StreamingProvider $provider,
        ExportDestinationType $type,
        ?int $streamingAccountId = null,
    ): ResolvedExportDestination {
        if ((int) $playlist->user_id !== (int) $user->getKey()) {
            $this->invalid('Ta playlista nie należy do użytkownika.');
        }

        $playlist->assertSource();

        return $type === ExportDestinationType::Linked
            ? $this->linked($user, $playlist, $provider, $streamingAccountId)
            : $this->managed($user, $playlist, $provider);
    }

    private function linked(User $user, Playlist $playlist, StreamingProvider $provider, ?int $accountId): ResolvedExportDestination
    {
        $account = $user->streamingAccounts()
            ->whereKey($accountId)
            ->where('provider', $provider->value)
            ->first();

        if (! $account instanceof StreamingAccount) {
            $this->invalid('Wybrane konto nie jest dostępne.');
        }

        if ($account->connectionState() === StreamingAccount::STATE_RECONNECT_REQUIRED) {
            $this->invalid('Połącz konto ponownie przed rozpoczęciem przeglądu.');
        }

        if (array_diff($provider->exportScopes(), $account->scopes ?? []) !== []) {
            $this->invalid('Połącz konto ponownie, aby udzielić uprawnień eksportu.');
        }

        if ($provider === StreamingProvider::Spotify && $account->market === null) {
            $this->hydrateSpotifyMarket($user, $account);
        }

        $this->guardDifferentSource($playlist, $provider, $account->provider_account_id);

        return new ResolvedExportDestination(
            $provider,
            ExportDestinationType::Linked,
            (int) $account->getKey(),
            $account->provider_account_id,
            $account->market,
        );
    }

    private function managed(User $user, Playlist $playlist, StreamingProvider $provider): ResolvedExportDestination
    {
        if ($user->streamingAccounts()
            ->where('provider', $provider->value)
            ->whereNotNull('refresh_token')
            ->exists()) {
            $this->invalid('Dla tego providera użyj aktywnego połączonego konta.');
        }

        $accountId = (string) config("services.managed_export.providers.{$provider->value}.account_id");
        $market = $provider === StreamingProvider::Spotify
            ? $this->market(config('services.managed_export.providers.spotify.market'))
            : null;

        if ($accountId === '') {
            $this->invalid('Cel zarządzany nie jest skonfigurowany.');
        }

        $this->guardDifferentSource($playlist, $provider, $accountId);

        return new ResolvedExportDestination($provider, ExportDestinationType::Managed, null, $accountId, $market);
    }

    private function hydrateSpotifyMarket(User $user, StreamingAccount $account): void
    {
        $market = null;
        $result = $this->access->handle(
            $user,
            $account,
            $account->provider->exportScopes(),
            function (StreamingAccessContext $context) use ($account, &$market): ?StreamingAccessFailure {
                $identity = $this->spotify->identity($context->accessToken);

                if (! $identity instanceof StreamingIdentity
                    || $identity->accountId !== $account->provider_account_id
                    || $identity->market === null) {
                    return StreamingAccessFailure::TemporarilyUnavailable;
                }

                $market = $identity->market;

                return null;
            },
        );

        if (! $result->successful || $market === null) {
            $this->invalid('Nie udało się ustalić rynku konta Spotify. Połącz konto ponownie.');
        }

        $account->forceFill(['market' => $market])->save();
    }

    private function guardDifferentSource(Playlist $playlist, StreamingProvider $provider, string $accountId): void
    {
        if ($playlist->source_provider === $provider && $playlist->source_account_id !== null
            && hash_equals($playlist->source_account_id, $accountId)) {
            $this->invalid('Źródło i cel eksportu nie mogą być tym samym kontem providera.');
        }
    }

    private function market(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{2}$/', $value) !== 1) {
            $this->invalid('Rynek Spotify dla celu zarządzanego nie jest skonfigurowany.');
        }

        return strtoupper($value);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['destination' => $message]);
    }
}
