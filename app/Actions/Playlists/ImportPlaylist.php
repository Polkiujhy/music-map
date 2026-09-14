<?php

namespace App\Actions\Playlists;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\ImportResult;
use App\Integrations\PlaylistImport\PlaylistShareUrlParser;
use App\Integrations\PlaylistImport\PlaylistSourceReaderRegistry;
use App\Integrations\PlaylistImport\Providers\SpotifyPlaylistReader;
use App\Integrations\StreamingAccounts\Contracts\WithStreamingAccess;
use App\Integrations\StreamingAccounts\Data\StreamingAccessContext;
use App\Integrations\StreamingAccounts\StreamingAccessFailure;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class ImportPlaylist
{
    public function __construct(
        private PlaylistShareUrlParser $parser,
        private PlaylistSourceReaderRegistry $readers,
        private ReplaceImportedPlaylist $replace,
        private WithStreamingAccess $withStreamingAccess,
    ) {}

    public function handle(User $user, string $submittedUrl): ImportResult
    {
        $correlationId = (string) Str::uuid();
        $reference = $this->parser->parse($submittedUrl);

        if ($reference instanceof ImportFailureCode) {
            return $this->failure($reference, null, $correlationId);
        }

        $streamingAccountId = null;

        if ($reference->provider === StreamingProvider::Spotify) {
            $account = $user->streamingAccounts()
                ->where('provider', StreamingProvider::Spotify->value)
                ->first();

            if ($account === null) {
                return $this->failure(
                    ImportFailureCode::LinkedAccountRequired,
                    StreamingProvider::Spotify,
                    $correlationId,
                );
            }

            $snapshot = null;
            $accessResult = $this->withStreamingAccess->handle(
                $user,
                $account,
                SpotifyPlaylistReader::REQUIRED_SCOPES,
                function (StreamingAccessContext $access) use ($account, $reference, &$snapshot): void {
                    if ($access->provider !== StreamingProvider::Spotify
                        || $access->providerAccountId !== $account->provider_account_id) {
                        $snapshot = ImportFailureCode::ReauthorizationRequired;

                        return;
                    }

                    $snapshot = $this->readers->readerFor(StreamingProvider::Spotify)?->read($reference, $access)
                        ?? ImportFailureCode::UnsupportedProvider;
                },
            );

            if (! $accessResult->successful) {
                return $this->failure(
                    $this->mapAccessFailure($accessResult->failure),
                    StreamingProvider::Spotify,
                    $correlationId,
                );
            }

            $snapshot ??= ImportFailureCode::InvalidResponse;
            $streamingAccountId = $account->getKey();
        } else {
            $reader = $this->readers->readerFor($reference->provider);
            $snapshot = $reader?->read($reference) ?? ImportFailureCode::UnsupportedProvider;
        }

        if ($snapshot instanceof ImportFailureCode) {
            return $this->failure($snapshot, $reference->provider, $correlationId);
        }

        $wasImported = $user->playlists()
            ->where('source_provider', $reference->provider->value)
            ->where('source_playlist_id', $reference->providerPlaylistId)
            ->exists();
        $playlist = $this->replace->handle(
            $user,
            $snapshot,
            streamingAccountId: $streamingAccountId,
        );

        return $wasImported
            ? ImportResult::refreshed($playlist->getKey(), $correlationId)
            : ImportResult::imported($playlist->getKey(), $correlationId);
    }

    private function failure(
        ImportFailureCode $code,
        ?StreamingProvider $provider,
        string $correlationId,
    ): ImportResult {
        Log::warning('playlist_import_failed', [
            'correlation_id' => $correlationId,
            'failure_code' => $code->value,
            'provider' => $provider?->value,
        ]);

        return ImportResult::failure($code, $provider, $correlationId);
    }

    private function mapAccessFailure(?StreamingAccessFailure $failure): ImportFailureCode
    {
        return match ($failure) {
            StreamingAccessFailure::ReconnectRequired,
            StreamingAccessFailure::StaleCredential => ImportFailureCode::ReauthorizationRequired,
            StreamingAccessFailure::MissingScope => ImportFailureCode::InsufficientScope,
            StreamingAccessFailure::RateLimited => ImportFailureCode::RateLimited,
            StreamingAccessFailure::QuotaExceeded => ImportFailureCode::QuotaLimited,
            StreamingAccessFailure::TemporarilyUnavailable,
            null => ImportFailureCode::ProviderUnavailable,
        };
    }
}
