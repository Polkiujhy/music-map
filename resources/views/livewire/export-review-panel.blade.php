<section aria-labelledby="review-heading" @if ($this->isPolling) wire:poll.3s="poll" @endif>
    @php
        $operation = $review->exportOperation;
        $targetAttempt = $operation?->playlistExport?->targetAttempts
            ?->where('status', '!=', \App\Models\PlaylistExportTargetAttempt::STATUS_ABANDONED)
            ->sortByDesc('generation')
            ->first();
        $targetUrl = $targetAttempt?->canonical_url ?? $operation?->playlistExport?->targetPlaylist?->canonical_source_url;
        $currentManagedAccount = $operation
            ? (string) config("services.platform_access.{$operation->playlistExport->target_provider->value}.technical.account_id")
            : null;
        $canUseHistoricalAccount = $operation
            && $currentManagedAccount !== ''
            && hash_equals($operation->playlistExport->target_account_id, $currentManagedAccount);
    @endphp
    <a href="{{ route('bank.index') }}" class="auth-link">← Wróć do banku</a>

    <div class="mt-6 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Przegląd przed eksportem</p>
        <h1 id="review-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $review->playlist->name ?? 'Playlista bez nazwy' }}</h1>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-ash-grey-400">Platforma docelowa</dt><dd class="mt-1 font-semibold">{{ $review->target_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</dd></div>
            <div><dt class="text-ash-grey-400">Właściciel celu</dt><dd class="mt-1 font-semibold">{{ $review->destination_type === \App\Enums\ExportDestinationType::Linked ? 'Twoje połączone konto' : 'Konto zarządzane przez Music Map' }}</dd></div>
            <div><dt class="text-ash-grey-400">Stan</dt><dd class="mt-1 font-semibold">{{ $this->statusLabel }}</dd></div>
            <div><dt class="text-ash-grey-400">Ważny do</dt><dd class="mt-1 font-semibold"><time datetime="{{ $review->expires_at->toIso8601String() }}">{{ $review->expires_at->format('Y-m-d H:i') }}</time></dd></div>
        </dl>

        <p aria-live="polite" aria-atomic="true" class="sr-only">{{ $statusAnnouncement }}</p>

        @if ($errors->any())
            <div role="alert" class="mt-6 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">
                <p class="font-semibold">Nie można wykonać tej operacji.</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('status'))
            <div role="status" class="mt-6 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ session('status') }}</div>
        @endif

        @if ($this->isPolling)
            <div class="mt-8 rounded-2xl border border-ash-grey-800 bg-ash-grey-950/40 p-6">
                <p class="font-semibold">{{ $this->statusLabel }}</p>
                @if ($this->elapsedSeconds >= 60)
                    <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Możesz bezpiecznie opuścić tę stronę. Po zakończeniu wyślemy jedną wiadomość e-mail.</p>
                @elseif ($this->elapsedSeconds >= 30)
                    <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Przetwarzanie nadal trwa. Wynik pojawi się tutaj automatycznie.</p>
                @else
                    <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Sprawdzamy całą playlistę. Nie publikujemy częściowego wyniku.</p>
                @endif
            </div>
        @elseif ($operation?->status === \App\Enums\ExportOperationStatus::Succeeded)
            <div class="mt-8 rounded-2xl border border-ash-grey-700 p-6">
                <h2 class="text-xl font-semibold">Przeniesiona — zarządzana przez music-map</h2>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Właściciel: konto zarządzane przez music-map.</p>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Zmiany wykonuj w playliście źródłowej w Music Map. Playlista docelowa w banku jest osobnym snapshotem tylko do odczytu.</p>
                @if ($targetUrl)
                    <a href="{{ $targetUrl }}" rel="noreferrer noopener" class="auth-link mt-5 inline-block">Otwórz przeniesioną playlistę</a>
                @endif
            </div>
        @elseif ($operation?->status === \App\Enums\ExportOperationStatus::Failed)
            <div class="mt-8 rounded-2xl border border-ash-grey-800 p-6">
                <h2 class="text-xl font-semibold">Nie przeniesiono</h2>
                @if ($this->failureMessage)
                    <p class="mt-2 text-sm text-ash-grey-200/70">Przyczyna: {{ $this->failureMessage }}</p>
                @endif
                @if ($operation->retry_available_at?->isFuture())
                    <p class="mt-3 text-sm text-ash-grey-400">Ponowienie będzie dostępne <time datetime="{{ $operation->retry_available_at->toIso8601String() }}">{{ $operation->retry_available_at->format('Y-m-d H:i:s') }}</time>.</p>
                @elseif ($canUseHistoricalAccount)
                    <form method="POST" action="{{ route('managed-exports.retry', [$review->playlist_id, $operation]) }}" class="mt-5">
                        @csrf
                        <button type="submit" class="primary-button">Spróbuj ponownie</button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-ash-grey-400">Ponowienie jest zablokowane, dopóki dostęp techniczny nie potwierdzi tego samego konta.</p>
                @endif
            </div>
        @elseif ($operation?->status === \App\Enums\ExportOperationStatus::PartialFailed)
            <div class="mt-8 rounded-2xl border border-ash-grey-800 p-6">
                <h2 class="text-xl font-semibold">Nie udało się dokończyć przenoszenia</h2>
                <p class="mt-3 text-sm leading-6 text-ash-grey-200/70">Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii</p>
                @if ($this->failureMessage)
                    <p class="mt-2 text-sm text-ash-grey-200/70">Przyczyna: {{ $this->failureMessage }}</p>
                @endif
                @if ($operation->retry_available_at?->isFuture())
                    <p class="mt-3 text-sm text-ash-grey-400">Ponowienie będzie dostępne <time datetime="{{ $operation->retry_available_at->toIso8601String() }}">{{ $operation->retry_available_at->format('Y-m-d H:i:s') }}</time>.</p>
                @elseif ($canUseHistoricalAccount)
                    <form method="POST" action="{{ route('managed-exports.retry', [$review->playlist_id, $operation]) }}" class="mt-5">
                        @csrf
                        <button type="submit" class="primary-button">Ponów na tej samej playliście</button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-ash-grey-400">Ponowienie jest zablokowane, dopóki dostęp techniczny nie potwierdzi tego samego konta.</p>
                @endif
            </div>
        @elseif (in_array($operation?->status, [\App\Enums\ExportOperationStatus::ManualRecoveryRequired, \App\Enums\ExportOperationStatus::RecreateRequired], true))
            <div class="mt-8 rounded-2xl border border-amber-800 bg-amber-950/20 p-6">
                <h2 class="text-xl font-semibold">Nie udało się dokończyć przenoszenia</h2>
                @if ($this->failureMessage)
                    <p class="mt-2 text-sm text-ash-grey-200/70">Przyczyna: {{ $this->failureMessage }}</p>
                @endif
                <p class="mt-3 text-sm leading-6 text-amber-100">Nie możemy bezpiecznie potwierdzić poprzedniego celu. Jawne odtworzenie może utworzyć nową playlistę i zmienić jej link. Poprzedni link oraz marker pozostaną w historii odzyskiwania.</p>
                @if ($canUseHistoricalAccount)
                    <form method="POST" action="{{ route('managed-exports.recreate', [$review->playlist_id, $operation]) }}" class="mt-5 space-y-4">
                        @csrf
                        <label class="flex items-start gap-3 text-sm leading-6">
                            <input name="confirm_recreation" type="checkbox" value="1" required class="mt-1 size-4 rounded border-ash-grey-700 bg-[#171717]">
                            <span>Rozumiem, że odtworzenie może utworzyć nowy link.</span>
                        </label>
                        <button type="submit" class="primary-button">Odtwórz cel z nowym linkiem</button>
                    </form>
                @else
                    <p class="mt-3 text-sm text-ash-grey-400">Odtworzenie jest zablokowane, dopóki dostęp techniczny nie potwierdzi tego samego konta.</p>
                @endif
            </div>
        @elseif (in_array($review->status, [\App\Enums\ExportReviewStatus::Failed, \App\Enums\ExportReviewStatus::Expired], true))
            <div class="mt-8 rounded-2xl border border-ash-grey-800 p-6">
                <p class="font-semibold">{{ $review->status === \App\Enums\ExportReviewStatus::Expired ? 'Ten wynik wygasł.' : 'Przygotowanie wyniku nie powiodło się.' }}</p>
                <p class="mt-2 text-sm text-ash-grey-200/70">Ponowienie utworzy świeży przegląd i jeszcze raz sprawdzi aktualną zawartość oraz cel.</p>
                <form method="POST" action="{{ route('export-reviews.retry', [$review->playlist_id, $review->getKey()]) }}" class="mt-5">
                    @csrf
                    <button type="submit" class="primary-button">Przygotuj nowy wynik</button>
                </form>
            </div>
        @elseif ($review->status === \App\Enums\ExportReviewStatus::Ready)
            @php
                $kept = $review->items->filter(fn ($item) => $item->match_status !== \App\Enums\ExportMatchStatus::Unavailable && ($decisions[$item->id] ?? null) === 'keep')->count();
                $skipped = $review->items->filter(fn ($item) => $item->match_status === \App\Enums\ExportMatchStatus::Unavailable && ($decisions[$item->id] ?? 'keep') !== 'remove')->count();
                $removed = collect($decisions)->filter(fn ($decision) => $decision === 'remove')->count();
            @endphp
            <div class="mt-8 grid gap-3 sm:grid-cols-3" aria-label="Podsumowanie decyzji">
                <div class="rounded-xl bg-ash-grey-900 p-4"><span class="text-sm text-ash-grey-400">Do eksportu</span><strong class="mt-1 block text-2xl">{{ $kept }}</strong></div>
                <div class="rounded-xl bg-ash-grey-900 p-4"><span class="text-sm text-ash-grey-400">Poza eksportem</span><strong class="mt-1 block text-2xl">{{ $skipped }}</strong></div>
                <div class="rounded-xl bg-ash-grey-900 p-4"><span class="text-sm text-ash-grey-400">Do usunięcia z banku</span><strong class="mt-1 block text-2xl">{{ $removed }}</strong></div>
            </div>

            <div class="mt-5 flex justify-end">
                <button type="button" wire:click="keepAll" wire:loading.attr="disabled" wire:target="keepAll" class="secondary-button">Zachowaj wszystkie w banku</button>
            </div>

            <div class="mt-8 space-y-5">
                @foreach ($review->items as $item)
                    @php
                        $sourceUnavailable = ! $item->source_is_available;
                        $sourceMissingTitle = $item->source_title === null;
                        $label = match (true) {
                            $sourceUnavailable => 'Źródło niedostępne',
                            $sourceMissingTitle => 'Brak danych źródłowych',
                            default => match ($item->match_status) {
                                \App\Enums\ExportMatchStatus::Matched => 'Pewne dopasowanie',
                                \App\Enums\ExportMatchStatus::Suspicious => 'Wymaga decyzji',
                                \App\Enums\ExportMatchStatus::Unavailable => 'Niedostępne w celu',
                            },
                        };
                    @endphp
                    <article wire:key="review-item-{{ $item->id }}" class="rounded-2xl border border-ash-grey-800 p-5 sm:p-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-ash-grey-400">Pozycja {{ $item->position + 1 }} · {{ $label }}</p>
                                <h2 class="mt-2 break-words text-lg font-semibold">{{ $item->source_title ?? 'Pozycja bez tytułu' }}</h2>
                                <p class="mt-1 break-words text-sm text-ash-grey-200/70">{{ implode(', ', $item->source_creators) ?: 'Nieznany wykonawca' }}</p>
                            </div>
                            @if ($item->target_title)
                                <div class="min-w-0 rounded-xl bg-ash-grey-900 p-4 sm:max-w-[48%]">
                                    <p class="text-xs text-ash-grey-400">Znaleziony odpowiednik</p>
                                    <p class="mt-1 break-words font-semibold">{{ $item->target_title }}</p>
                                    <p class="mt-1 break-words text-sm text-ash-grey-200/70">{{ implode(', ', $item->target_creators ?? []) }}</p>
                                </div>
                            @else
                                @if ($sourceUnavailable)
                                    <p class="text-sm text-ash-grey-200/70">Pozycja źródłowa jest niedostępna i nie została wyszukana w katalogu celu.</p>
                                @elseif ($sourceMissingTitle)
                                    <p class="text-sm text-ash-grey-200/70">Brak tytułu potrzebnego do wyszukania tej pozycji w katalogu celu.</p>
                                @else
                                    <p class="text-sm text-ash-grey-200/70">Nie znaleziono wiarygodnego odpowiednika w katalogu celu.</p>
                                @endif
                            @endif
                        </div>

                        @if ($item->match_status !== \App\Enums\ExportMatchStatus::Matched)
                            <fieldset class="mt-5">
                                <legend class="text-sm font-semibold">Co zrobić z tą pozycją w banku?</legend>
                                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                                    @foreach (['keep' => 'Zachowaj w banku', 'remove' => 'Usuń z banku przy potwierdzeniu'] as $value => $text)
                                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ash-grey-700 p-4 focus-within:ring-2 focus-within:ring-ash-grey-300">
                                            <input type="radio" name="decisions[{{ $item->id }}]" value="{{ $value }}" wire:model="decisions.{{ $item->id }}" wire:change="choose({{ $item->id }}, '{{ $value }}')" class="mt-1 size-4 border-ash-grey-700 bg-[#171717] text-ash-grey-400 accent-ash-grey-400 focus:ring-ash-grey-400">
                                            <span class="text-sm">{{ $text }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @else
                            <p class="mt-5 text-sm text-ash-grey-200/70">Ta pozycja zostanie zachowana i dodana do manifestu.</p>
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="mt-8 rounded-2xl border border-ash-grey-700 p-5">
                <h2 class="text-lg font-semibold">Świadome potwierdzenie</h2>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Potwierdzenie atomowo zastosuje zaznaczone usunięcia i zamrozi dokładne ID docelowe. Nie zapisuje jeszcze playlisty na platformie.</p>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="primary-button mt-5">Potwierdź decyzje i zamroź manifest</button>
            </div>
        @elseif ($review->status === \App\Enums\ExportReviewStatus::Confirmed)
            <div class="mt-8 rounded-2xl border border-ash-grey-700 p-6">
                <h2 class="text-xl font-semibold">W trakcie przenoszenia</h2>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Manifest został zamrożony. Operacja będzie kontynuowana w tle.</p>
            </div>
        @endif
    </div>
</section>
