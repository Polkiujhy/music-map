<?php

namespace App\Actions\Playlists;

use App\Enums\StreamingProvider;
use App\Integrations\PlaylistImport\ImportFailureCode;
use App\Integrations\PlaylistImport\ImportResult;
use App\Integrations\PlaylistImport\PlaylistShareUrlParser;
use App\Integrations\PlaylistImport\PlaylistSourceReaderRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class ImportPlaylist
{
    public function __construct(
        private PlaylistShareUrlParser $parser,
        private PlaylistSourceReaderRegistry $readers,
        private ReplaceImportedPlaylist $replace,
    ) {}

    public function handle(User $user, string $submittedUrl): ImportResult
    {
        $correlationId = (string) Str::uuid();
        $reference = $this->parser->parse($submittedUrl);

        if ($reference instanceof ImportFailureCode) {
            return $this->failure($reference, null, $correlationId);
        }

        $reader = $this->readers->readerFor($reference->provider);

        if ($reader === null) {
            $code = $reference->provider === StreamingProvider::Spotify
                ? ImportFailureCode::LinkedAccountRequired
                : ImportFailureCode::UnsupportedProvider;

            return $this->failure($code, $reference->provider, $correlationId);
        }

        $snapshot = $reader->read($reference);

        if ($snapshot instanceof ImportFailureCode) {
            return $this->failure($snapshot, $reference->provider, $correlationId);
        }

        $wasImported = $user->playlists()
            ->where('source_provider', $reference->provider->value)
            ->where('source_playlist_id', $reference->providerPlaylistId)
            ->exists();
        $playlist = $this->replace->handle($user, $snapshot);

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
}
