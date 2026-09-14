<?php

namespace App\Actions\PlaylistSync;

use App\Actions\Playlists\FingerprintPlaylistContent;
use App\Enums\PlaylistSyncDirection;
use App\Enums\PlaylistSyncStatus;
use App\Enums\StreamingProvider;
use App\Integrations\PlaylistSync\Data\SourcePlaylistSnapshot;
use App\Integrations\PlaylistSync\SourcePlaylistReaderRegistry;
use App\Integrations\PlaylistSync\SourceSyncFailure;
use App\Integrations\StreamingAccounts\Actions\MarkStreamingAccountReconnectRequired;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\Playlist;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PreparePlaylistSynchronization
{
    public const PREVIEW_TTL_MINUTES = 15;

    public function __construct(
        private SourcePlaylistReaderRegistry $readers,
        private WithStreamingAccess $withStreamingAccess,
        private FingerprintPlaylistContent $bankFingerprint,
        private FingerprintSourcePlaylist $sourceFingerprint,
        private MarkStreamingAccountReconnectRequired $markReconnectRequired,
    ) {}

    /** @return array{preview_token:string,direction:string,bank_item_count:int,source_item_count:int,change_count:int,expires_at:string} */
    public function handle(User $user, Playlist $playlist): array
    {
        $playlist = $user->playlists()->whereKey($playlist->getKey())->with('items')->firstOrFail();
        $account = $user->streamingAccounts()
            ->where('provider', $playlist->source_provider->value)
            ->first();

        if (! $account instanceof StreamingAccount) {
            $this->invalid('account', 'Połącz konto źródłowego dostawcy przed aktywacją synchronizacji.');
        }

        $requiredScopes = $playlist->source_provider->requiredScopes();
        if (array_diff($requiredScopes, $account->scopes) !== []) {
            $this->markReconnectRequired->handle($account, $account->credential_version);
            $this->invalid('account', 'Konto wymaga ponownego połączenia i zgody na wymagane uprawnienia.');
        }

        $reader = $this->readers->readerFor($playlist->source_provider);
        if ($reader === null) {
            $this->invalid('playlist', 'Ten dostawca nie obsługuje synchronizacji.');
        }

        $snapshot = null;
        $accessResult = $this->withStreamingAccess->handle(
            $user,
            $account,
            $requiredScopes,
            function (StreamingAccessContext $access) use ($reader, $playlist, &$snapshot): void {
                $snapshot = $reader->read($playlist->source_playlist_id, $access);
            },
        );

        if (! $accessResult->successful) {
            $this->invalid('account', $this->accessMessage($accessResult->failure));
        }
        if ($snapshot instanceof SourceSyncFailure) {
            $this->invalid('playlist', $this->sourceMessage($snapshot));
        }
        if (! $snapshot instanceof SourcePlaylistSnapshot) {
            $this->invalid('playlist', 'Nie udało się odczytać kompletnego stanu playlisty źródłowej.');
        }

        if (! hash_equals($account->provider_account_id, (string) $snapshot->ownerAccountId)) {
            $this->invalid('playlist', 'Synchronizować można wyłącznie playlistę należącą do połączonego konta.');
        }

        $bankFingerprint = $this->bankFingerprint->handle($playlist);
        $sourceFingerprint = $this->sourceFingerprint->handle($snapshot);
        $direction = $this->initialDirection($playlist, $snapshot);
        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::PREVIEW_TTL_MINUTES);

        Cache::put($this->cacheKey($token), [
            'user_id' => (int) $user->getKey(),
            'playlist_id' => (int) $playlist->getKey(),
            'streaming_account_id' => (int) $account->getKey(),
            'bank_fingerprint' => $bankFingerprint,
            'source_fingerprint' => $sourceFingerprint,
            'provider_revision' => $snapshot->providerRevision,
            'owner_account_id' => $snapshot->ownerAccountId,
            'direction' => $direction->value,
            'bank_snapshot' => $this->bankSnapshot($playlist),
            'source_snapshot' => $snapshot->items,
        ], $expiresAt);

        $playlist->synchronization()->updateOrCreate([], [
            'streaming_account_id' => $account->getKey(),
            'status' => PlaylistSyncStatus::PendingConfirmation,
            'automatic_enabled' => false,
            'last_failure_code' => null,
        ]);

        $bankCount = $playlist->items->count();
        $sourceCount = count($snapshot->itemIdentifiers);

        return [
            'preview_token' => $token,
            'direction' => $direction->value,
            'bank_item_count' => $bankCount,
            'source_item_count' => $sourceCount,
            'change_count' => $direction === PlaylistSyncDirection::NoOp ? 0 : ($direction === PlaylistSyncDirection::Pull ? $sourceCount : $bankCount),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function initialDirection(Playlist $playlist, SourcePlaylistSnapshot $source): PlaylistSyncDirection
    {
        $bankIdentifiers = $playlist->items->map(function ($item) use ($playlist): string {
            if ($playlist->source_provider === StreamingProvider::Spotify) {
                return (string) ($item->catalog_id ?? $item->catalog_uri);
            }

            return (string) $item->catalog_id;
        })->all();

        if ($bankIdentifiers === $source->normalizedItemIdentifiers()) {
            return PlaylistSyncDirection::NoOp;
        }

        return $playlist->bank_content_edited_at !== null
            && $playlist->provider_revision === $source->providerRevision
                ? PlaylistSyncDirection::Push
                : PlaylistSyncDirection::Pull;
    }

    /** @return list<array<string, mixed>> */
    private function bankSnapshot(Playlist $playlist): array
    {
        return $playlist->items->map(fn ($item): array => [
            'position' => $item->position,
            'provider_item_id' => $item->occurrence_id,
            'catalog_id' => $item->catalog_id,
            'catalog_uri' => $item->catalog_uri,
            'title' => $item->title,
            'creators' => $item->creators,
            'album' => $item->album,
            'duration_milliseconds' => $item->duration_milliseconds,
            'isrc' => $item->isrc,
            'is_available' => $item->is_available,
        ])->values()->all();
    }

    public static function cacheKey(string $token): string
    {
        return 'playlist-sync-preview:'.hash('sha256', $token);
    }

    private function accessMessage(?StreamingAccessFailure $failure): string
    {
        return match ($failure) {
            StreamingAccessFailure::ReconnectRequired,
            StreamingAccessFailure::MissingScope,
            StreamingAccessFailure::StaleCredential => 'Konto wymaga ponownego połączenia.',
            StreamingAccessFailure::RateLimited => 'Dostawca ograniczył liczbę żądań. Spróbuj ponownie później.',
            default => 'Dostawca jest chwilowo niedostępny.',
        };
    }

    private function sourceMessage(SourceSyncFailure $failure): string
    {
        return match ($failure) {
            SourceSyncFailure::OverLimit => 'Playlista źródłowa może zawierać maksymalnie 20 pozycji.',
            SourceSyncFailure::NotFound => 'Playlista źródłowa nie istnieje.',
            SourceSyncFailure::Forbidden,
            SourceSyncFailure::OwnerMismatch => 'Połączone konto nie jest właścicielem tej playlisty.',
            SourceSyncFailure::Unauthorized,
            SourceSyncFailure::ReconnectRequired => 'Konto wymaga ponownego połączenia.',
            SourceSyncFailure::RateLimited => 'Dostawca ograniczył liczbę żądań. Spróbuj ponownie później.',
            default => 'Nie udało się odczytać kompletnego stanu playlisty źródłowej.',
        };
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
