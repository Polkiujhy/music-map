@extends('layouts.app')

@section('title', 'Przeglądaj i edytuj playlistę')

@section('content')
    <section aria-labelledby="playlist-editing-heading" class="mx-auto max-w-5xl">
        <a href="{{ route('bank.index') }}" class="auth-link">Wróć do banku</a>

        @if (session('status'))
            <div role="status" class="mt-6 rounded-xl border border-ash-grey-600 bg-ash-grey-900/70 px-4 py-3 text-ash-grey-100">{{ session('status') }}</div>
        @endif

        @if (session('error') || $errors->has('confirm_reimport'))
            <div role="alert" class="mt-6 rounded-xl border border-red-800 bg-red-950/30 px-4 py-3 text-red-200">{{ session('error') ?: $errors->first('confirm_reimport') }}</div>
        @endif

        <div class="mt-6 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-2xl shadow-black/20 sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Źródło: {{ $playlist->source_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</p>
            <h1 id="playlist-editing-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $playlist->name ?? 'Playlista — dane wymagają odświeżenia' }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-ash-grey-200/70">Nazwa, opis i dane źródłowe są tylko do odczytu. Zmieniasz wyłącznie kolejność lub obecność zapisanych pozycji.</p>
            <a href="{{ $playlist->canonical_source_url }}" rel="noreferrer noopener" class="auth-link mt-5 inline-block">Otwórz źródło</a>

            <div class="mt-6 border-t border-ash-grey-800 pt-6">
                <p class="text-sm leading-6 text-ash-grey-200/70">Automatyczne odświeżenie zachowuje lokalną kolejność i usunięcia. Pełny reimport przywraca dokładną zawartość z platformy.</p>
                <flux:modal.trigger name="confirm-playlist-reimport">
                    <button type="button" class="danger-button mt-4">Pełny reimport z platformy</button>
                </flux:modal.trigger>
            </div>
        </div>

        <livewire:playlist-editor :playlist-id="(string) $playlist->getKey()" />

        @include('playlists._synchronization', ['playlist' => $playlist])

        <flux:modal name="confirm-playlist-reimport" class="max-w-lg">
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Pełny reimport</p>
                <h2 class="mt-3 text-2xl font-semibold">Zastąpić lokalną wersję playlisty?</h2>
                <p class="mt-4 leading-7 text-ash-grey-200/80">Reimport pobierze pełną wersję z platformy i bezpowrotnie zastąpi lokalną kolejność oraz wszystkie lokalne usunięcia.</p>
                <div class="mt-7 flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <button type="button" class="secondary-button">Anuluj</button>
                    </flux:modal.close>
                    <form method="POST" action="{{ route('bank.playlists.reimport', $playlist) }}">
                        @csrf
                        <input type="hidden" name="confirm_reimport" value="yes">
                        <button type="submit" class="danger-button">Potwierdź pełny reimport</button>
                    </form>
                </div>
            </div>
        </flux:modal>
    </section>
@endsection
