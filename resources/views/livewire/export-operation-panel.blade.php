<section aria-labelledby="export-operation-heading" @if ($this->isPolling) wire:poll.3s="poll" @endif>
    <a href="{{ route('bank.index') }}" class="auth-link">← Wróć do banku</a>

    <div class="mt-6 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 sm:p-8">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Wynik eksportu</p>
        <h1 id="export-operation-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">
            {{ $operation->sourcePlaylist->name ?? 'Playlista bez nazwy' }}
        </h1>

        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-ash-grey-400">Platforma docelowa</dt><dd class="mt-1 font-semibold">{{ $operation->target_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</dd></div>
            <div><dt class="text-ash-grey-400">Właściciel celu</dt><dd class="mt-1 font-semibold">{{ $operation->destination_type === \App\Enums\ExportDestinationType::Linked ? 'Twoje połączone konto' : 'Konto zarządzane przez music-map' }}</dd></div>
            <div><dt class="text-ash-grey-400">Stan</dt><dd class="mt-1 font-semibold">{{ $this->statusLabel }}</dd></div>
            <div><dt class="text-ash-grey-400">Identyfikator operacji</dt><dd class="mt-1 break-all font-mono text-xs">{{ $operation->operation_id }}</dd></div>
        </dl>

        <p aria-live="polite" aria-atomic="true" class="sr-only">{{ $statusAnnouncement }}</p>

        @if ($errors->any())
            <div role="alert" tabindex="-1" class="mt-6 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">
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
            <div wire:key="operation-active-{{ $status }}" role="status" class="mt-8 rounded-2xl border border-ash-grey-800 bg-ash-grey-950/40 p-6">
                <h2 class="text-xl font-semibold">W trakcie przenoszenia</h2>
                <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Możesz bezpiecznie opuścić tę stronę. Eksport będzie kontynuowany w tle, a po dłuższej operacji wyślemy powiadomienie.</p>
            </div>
        @elseif ($operation->status === \App\Enums\ExportOperationStatus::Transferred)
            <div wire:key="operation-transferred" role="status" tabindex="-1" class="mt-8 rounded-2xl border border-ash-grey-700 p-6">
                <h2 class="text-xl font-semibold">{{ $this->statusLabel }}</h2>
                @if ($operation->destination_type === \App\Enums\ExportDestinationType::Linked)
                    <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Playlista jest na Twoim koncie. Dalsze zmiany możesz wykonywać bezpośrednio na platformie albo przygotować świeży przegląd w music-map.</p>
                @else
                    <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Playlista jest zarządzana przez music-map. Otwórz ją przez stały link; kolejną aktualizację rozpoczniesz z banku.</p>
                @endif
                @if ($this->targetUrl)
                    <a href="{{ $this->targetUrl }}" rel="noreferrer noopener" class="primary-button mt-5 inline-flex">Otwórz przeniesioną playlistę</a>
                @endif
            </div>
        @else
            <div wire:key="operation-failed-{{ $status }}" role="alert" tabindex="-1" class="mt-8 rounded-2xl border border-red-800 bg-red-950/20 p-6">
                <h2 class="text-xl font-semibold">{{ $this->statusLabel }}</h2>
                @if ($this->failureReason)
                    <p class="mt-2 text-sm leading-6 text-red-100/80">{{ $this->failureReason }}</p>
                @endif

                @if ($operation->status === \App\Enums\ExportOperationStatus::Incomplete)
                    <p class="mt-3 text-sm leading-6 text-red-100/80">Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii</p>
                @endif

                @if ($this->needsRecoveryDecision)
                    <div class="mt-5 rounded-xl border border-red-700/70 p-4">
                        <h3 class="font-semibold">Bezpieczne odzyskanie po niejednoznacznym utworzeniu</h3>
                        <ol class="mt-2 list-decimal space-y-2 pl-5 text-sm leading-6 text-red-100/80">
                            <li>Otwórz swoje playlisty na platformie i wyszukaj kopie o nazwie „{{ $operation->playlist_name }}”.</li>
                            <li>Usuń wszystkie niejednoznaczne lub osierocone kopie, których nie chcesz zachować.</li>
                            <li>Ponowienie wykona ono wyłącznie skan i nie utworzy nowej playlisty.</li>
                        </ol>
                    </div>
                @endif

                @if ($this->canRetry)
                    <button type="button" wire:click="retry" wire:loading.attr="disabled" wire:target="retry,abandonRecovery" class="primary-button mt-5">Ponów tę samą operację</button>
                @endif

                @if ($this->needsRecoveryDecision)
                    <form wire:submit="abandonRecovery" class="mt-6 border-t border-red-800/70 pt-5">
                        <label class="flex items-start gap-3 text-sm leading-6 text-red-100/80">
                            <input type="checkbox" wire:model="orphanCopiesChecked" class="mt-1 size-4 rounded border-red-700 bg-[#171717] text-ash-grey-400 focus:ring-ash-grey-400">
                            <span>Sprawdziłem playlisty u providera i usunąłem ewentualne osierocone kopie.</span>
                        </label>
                        <button type="submit" wire:loading.attr="disabled" wire:target="retry,abandonRecovery" class="secondary-button mt-4">Porzuć recovery i wróć do nowego przeglądu</button>
                    </form>
                @endif

                @if ($this->canStartNewReview)
                    <form method="POST" action="{{ route('export-reviews.store', $operation->source_playlist_id) }}" class="mt-5">
                        @csrf
                        <input type="hidden" name="target_provider" value="{{ $operation->target_provider->value }}">
                        <input type="hidden" name="destination_type" value="{{ $operation->destination_type->value }}">
                        @if ($operation->streaming_account_id !== null)
                            <input type="hidden" name="streaming_account_id" value="{{ $operation->streaming_account_id }}">
                        @endif
                        <button type="submit" class="primary-button">Przygotuj nowy przegląd</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</section>
