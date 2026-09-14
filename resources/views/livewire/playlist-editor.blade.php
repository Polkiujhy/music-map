<section aria-labelledby="playlist-items-heading" class="mt-8 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-2xl shadow-black/20 sm:p-8">
    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Szkic lokalny</p>
            <h2 id="playlist-items-heading" class="mt-2 text-2xl font-semibold">Kolejność pozycji</h2>
            <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Ruchy i usunięcia nie zmieniają banku, dopóki nie wybierzesz „Zapisz zmiany”.</p>
        </div>
        <span class="text-sm font-semibold text-ash-grey-300">{{ count($orderedItemIds) }} / 20 pozycji</span>
    </div>

    @if ($conflictMessage)
        <div role="alert" class="mt-6 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">
            <p>{{ $conflictMessage }}</p>
            <a href="{{ route('bank.playlists.edit', $playlistId) }}" class="mt-2 inline-block font-semibold underline underline-offset-4">Załaduj aktualną wersję</a>
        </div>
    @endif

    @if ($statusMessage)
        <div role="status" class="mt-6 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ $statusMessage }}</div>
    @endif

    @if ($orderedItemIds === [])
        <div class="mt-6 rounded-2xl border border-dashed border-ash-grey-700 px-5 py-10 text-center">
            <p class="text-lg font-semibold">Ta playlista nie zawiera pozycji</p>
            <p class="mt-2 text-sm leading-6 text-ash-grey-200/70">Jeżeli dane źródłowe wygasły, zaimportuj playlistę ponownie z poziomu banku.</p>
        </div>
    @else
        <ol class="mt-6 space-y-3">
            @foreach ($orderedItemIds as $index => $itemId)
                @php
                    $item = $itemDetails[$itemId];
                    $position = $index + 1;
                    $duplicate = $item['catalog_id'] && ($catalogCounts[$item['catalog_id']] ?? 0) > 1;
                @endphp
                <li wire:key="playlist-item-{{ $itemId }}" class="flex flex-col gap-4 rounded-2xl border border-ash-grey-800 bg-ash-grey-950/30 p-4 sm:flex-row sm:items-center">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-ash-grey-900 text-sm font-bold text-ash-grey-200" aria-label="Pozycja {{ $position }}">{{ $position }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ $item['title'] ?: 'Niedostępna pozycja' }}</h3>
                            @if (! $item['is_available'])
                                <span class="rounded-full border border-amber-700 px-2 py-0.5 text-xs font-semibold text-amber-200">Placeholder — niedostępna</span>
                            @endif
                            @if ($duplicate)
                                <span class="rounded-full border border-ash-grey-700 px-2 py-0.5 text-xs font-semibold text-ash-grey-300">Duplikat</span>
                            @endif
                        </div>
                        @if ($item['creators'] !== [])
                            <p class="mt-1 text-sm text-ash-grey-200/70">{{ implode(', ', $item['creators']) }}</p>
                        @endif
                        @if ($item['album'])
                            <p class="mt-1 text-xs text-ash-grey-400">Album: {{ $item['album'] }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2" aria-label="Działania dla pozycji {{ $position }}">
                        <button type="button" wire:click="moveUp({{ $index }})" @disabled($index === 0) aria-label="Przenieś pozycję {{ $position }} w górę" class="secondary-button">↑</button>
                        <button type="button" wire:click="moveDown({{ $index }})" @disabled($index === count($orderedItemIds) - 1) aria-label="Przenieś pozycję {{ $position }} w dół" class="secondary-button">↓</button>
                        @if (count($orderedItemIds) === 1)
                            <flux:modal.trigger name="confirm-empty-playlist">
                                <button type="button" wire:click="removeItem({{ $index }})" aria-label="Usuń pozycję {{ $position }}" class="danger-button">Usuń</button>
                            </flux:modal.trigger>
                        @else
                            <button type="button" wire:click="removeItem({{ $index }})" aria-label="Usuń pozycję {{ $position }}" class="danger-button">Usuń</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    <div class="mt-7 flex flex-wrap items-center justify-between gap-4 border-t border-ash-grey-800 pt-6">
        <p class="text-sm text-ash-grey-200/70">Zapis obejmuje cały widoczny szkic w jednym kroku.</p>
        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="primary-button">Zapisz zmiany</button>
    </div>

    <flux:modal name="confirm-empty-playlist" class="max-w-lg">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Pusta playlista</p>
            <h2 class="mt-3 text-2xl font-semibold">Usunąć ostatnią pozycję?</h2>
            <p class="mt-4 leading-7 text-ash-grey-200/80">Playlista pozostanie w banku, ale będzie pusta. Zmiana nadal wymaga użycia przycisku „Zapisz zmiany”.</p>
            <div class="mt-7 flex flex-wrap justify-end gap-3">
                <flux:modal.close>
                    <button type="button" wire:click="cancelEmptyRemoval" class="secondary-button">Anuluj</button>
                </flux:modal.close>
                <flux:modal.close>
                    <button type="button" wire:click="confirmEmptyRemoval" class="danger-button">Usuń ostatnią pozycję</button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
