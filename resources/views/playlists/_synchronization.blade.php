@php
    $playlist->loadMissing('synchronization.latestRun');
    $synchronization = $playlist->synchronization;
    $latestRun = $synchronization?->latestRun;
    $provider = $playlist->source_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube';
    $account = auth()->user()->streamingAccounts()->where('provider', $playlist->source_provider->value)->first();
    $hasRequiredScopes = $account !== null && array_diff($playlist->source_provider->requiredScopes(), $account->scopes) === [];
    $canPrepare = $account !== null && $account->connectionState() === \App\Models\StreamingAccount::STATE_CONNECTED && $hasRequiredScopes;
    $preview = session('playlist_sync_preview');
    $displayState = match (true) {
        $latestRun?->state === 'running' => ['Trwa', 'Synchronizacja jest wykonywana.'],
        $latestRun?->state === 'pending' => ['Oczekuje', 'Synchronizacja oczekuje w kolejce.'],
        $synchronization?->status === \App\Enums\PlaylistSyncStatus::Attention => ['Wymaga uwagi', 'Automatyczne sprawdzanie jest wstrzymane.'],
        $synchronization?->status === \App\Enums\PlaylistSyncStatus::Disabled => ['Wyłączona', 'Połącz konto i przygotuj synchronizację ponownie.'],
        $synchronization?->status === \App\Enums\PlaylistSyncStatus::PendingConfirmation => ['Oczekuje na potwierdzenie', 'Przygotuj świeży podgląd i jawnie go potwierdź.'],
        $synchronization?->last_outcome !== null => ['Sukces', 'Ostatnia synchronizacja zakończyła się powodzeniem.'],
        $synchronization?->status === \App\Enums\PlaylistSyncStatus::Enabled => ['Włączona', 'Synchronizacja jest gotowa do uruchomienia.'],
        default => ['Nieaktywna', 'Najpierw sprawdź, czy playlista należy do połączonego konta.'],
    };
    $outcomeLabel = match ($synchronization?->last_outcome) {
        \App\Enums\PlaylistSyncOutcome::NoOp => 'Obie strony były zgodne',
        \App\Enums\PlaylistSyncOutcome::Pulled => 'Bank został odświeżony ze źródła',
        \App\Enums\PlaylistSyncOutcome::Pushed => 'Źródło zostało odświeżone z banku',
        \App\Enums\PlaylistSyncOutcome::Failed => 'Synchronizacja nie została wykonana',
        default => 'Brak zakończonej próby',
    };
    $attentionMessage = match ($synchronization?->last_failure_code) {
        'over-limit' => 'Playlista źródłowa przekracza limit 20 pozycji. Zmniejsz ją, a potem przygotuj synchronizację ponownie.',
        'not-found' => 'Nie można odnaleźć playlisty źródłowej. Sprawdź ją na platformie i przygotuj synchronizację ponownie.',
        'unauthorized', 'forbidden', 'owner-mismatch', 'missing-access', 'reconnect-required' => 'Połącz konto ponownie, a następnie przygotuj i potwierdź synchronizację.',
        'rate-limited' => 'Platforma chwilowo ograniczyła żądania. Spróbuj ponownie później.',
        'quota-limited' => 'Dzienny limit YouTube został wyczerpany. Spróbuj ponownie po odnowieniu limitu.',
        'write-admission-limited' => 'Dzienny limit zapisów aplikacji został osiągnięty. Spróbuj ponownie później.',
        'provider-unavailable' => 'Platforma jest chwilowo niedostępna. Spróbuj przygotować synchronizację ponownie później.',
        null => null,
        default => 'Synchronizacja wymaga ponownego przygotowania i potwierdzenia.',
    };
@endphp

<section aria-labelledby="playlist-synchronization-heading" class="mt-8 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-2xl shadow-black/20 sm:p-8">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Źródło: {{ $provider }}</p>
            <h2 id="playlist-synchronization-heading" class="mt-2 text-2xl font-semibold">Synchronizacja źródła</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-ash-grey-200/70">Porównuje bank z playlistą należącą do Twojego połączonego konta. Konflikt przy aktywnej synchronizacji bez dodatkowego pytania przywraca wersję źródłową.</p>
        </div>
        <span class="sync-status-pill">{{ $displayState[0] }}</span>
    </div>

    <p role="status" class="mt-5 rounded-xl border border-ash-grey-800 bg-ash-grey-950/30 px-4 py-3 text-sm text-ash-grey-100">{{ $displayState[1] }}</p>

    @if ($errors->hasAny(['account', 'playlist', 'preview_token', 'automatic_enabled', 'synchronization']))
        <div role="alert" class="mt-5 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200" tabindex="-1">
            <p class="font-semibold">Nie można zmienić synchronizacji.</p>
            <ul class="mt-2 list-disc pl-5">
                @foreach (['account', 'playlist', 'preview_token', 'automatic_enabled', 'synchronization'] as $field)
                    @foreach ($errors->get($field) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    @if ($synchronization?->status === \App\Enums\PlaylistSyncStatus::Attention && $attentionMessage)
        <div role="alert" class="mt-5 rounded-xl border border-amber-700 bg-amber-950/20 px-4 py-3 text-amber-100">
            <p class="font-semibold">Synchronizacja została zatrzymana bez zmiany banku.</p>
            <p class="mt-1 text-sm leading-6">{{ $attentionMessage }}</p>
        </div>
    @endif

    <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-3">
        <div><dt class="text-ash-grey-400">Dostawca</dt><dd class="mt-1 font-semibold">{{ $provider }}</dd></div>
        <div><dt class="text-ash-grey-400">Ostatnie sprawdzenie</dt><dd class="mt-1 font-semibold">@if ($synchronization?->last_checked_at)<time datetime="{{ $synchronization->last_checked_at->toIso8601String() }}">{{ $synchronization->last_checked_at->format('Y-m-d H:i') }}</time>@else Jeszcze nie sprawdzono @endif</dd></div>
        <div><dt class="text-ash-grey-400">Ostatni wynik</dt><dd class="mt-1 font-semibold">{{ $outcomeLabel }}</dd></div>
    </dl>

    @if (is_array($preview))
        @php
            $previewDirection = match ($preview['direction'] ?? null) {
                'pull' => 'Bank zostanie zastąpiony aktualną zawartością źródła.',
                'push' => 'Źródło zostanie zastąpione aktualną zawartością banku.',
                default => 'Obie strony są zgodne; potwierdzenie zapisze wspólną bazę.',
            };
        @endphp
        <div class="mt-6 rounded-2xl border border-ash-grey-700 p-5" aria-labelledby="synchronization-preview-heading">
            <h3 id="synchronization-preview-heading" class="text-lg font-semibold">Podgląd pierwszego uzgodnienia</h3>
            <p class="mt-2 text-sm leading-6 text-ash-grey-200/80">{{ $previewDirection }}</p>
            <p class="mt-2 text-sm text-ash-grey-200/70">Zmiana obejmie {{ (int) ($preview['change_count'] ?? 0) }} pozycji. Bank: {{ (int) ($preview['bank_item_count'] ?? 0) }}, źródło: {{ (int) ($preview['source_item_count'] ?? 0) }}.</p>
            <form method="POST" action="{{ route('playlist-synchronizations.confirm', $playlist) }}" class="mt-5">
                @csrf
                <input type="hidden" name="preview_token" value="{{ $preview['preview_token'] }}">
                <button type="submit" class="primary-button">Potwierdź pierwszą synchronizację</button>
            </form>
        </div>
    @else
        <div class="mt-6">
            @if (! $canPrepare)
                <p class="text-sm leading-6 text-ash-grey-200/80">Aby sprawdzić własność i przygotować podgląd, połącz konto {{ $provider }} z wymaganymi uprawnieniami.</p>
                <a href="{{ route('integrations.index') }}" class="auth-link mt-3 inline-block">Przejdź do połączeń kont</a>
            @else
                <form method="POST" action="{{ route('playlist-synchronizations.prepare', $playlist) }}">
                    @csrf
                    <button type="submit" class="secondary-button">Przygotuj podgląd synchronizacji</button>
                </form>
            @endif
        </div>
    @endif

    @if ($synchronization?->status === \App\Enums\PlaylistSyncStatus::Enabled)
        <div class="mt-7 grid gap-5 border-t border-ash-grey-800 pt-6 sm:grid-cols-2">
            <div>
                <h3 class="font-semibold">Uruchom ręcznie</h3>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Żądanie tylko dodaje zadanie do kolejki i nie czeka na platformę.</p>
                <form method="POST" action="{{ route('playlist-synchronizations.run', $playlist) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="primary-button">Uruchom synchronizację</button>
                </form>
            </div>
            <div>
                <h3 class="font-semibold">Tryb automatyczny</h3>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">To osobny wybór; potwierdzenie pierwszej synchronizacji go nie włącza.</p>
                <form method="POST" action="{{ route('playlist-synchronizations.update', $playlist) }}" class="mt-4">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="automatic_enabled" value="{{ $synchronization->automatic_enabled ? '0' : '1' }}">
                    <button type="submit" class="secondary-button" aria-pressed="{{ $synchronization->automatic_enabled ? 'true' : 'false' }}">
                        {{ $synchronization->automatic_enabled ? 'Wyłącz automatyczną synchronizację' : 'Włącz automatyczną synchronizację' }}
                    </button>
                </form>
            </div>
        </div>
    @endif
</section>
