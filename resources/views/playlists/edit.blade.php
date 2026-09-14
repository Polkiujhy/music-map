@extends('layouts.app')

@section('title', 'Przeglądaj i edytuj playlistę')

@section('content')
    <section aria-labelledby="playlist-editing-heading" class="mx-auto max-w-5xl">
        <a href="{{ route('bank.index') }}" class="auth-link">Wróć do banku</a>

        <div class="mt-6 rounded-3xl border border-ash-grey-900 bg-[#1d211e] p-6 shadow-2xl shadow-black/20 sm:p-8">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Źródło: {{ $playlist->source_provider === \App\Enums\StreamingProvider::Spotify ? 'Spotify' : 'YouTube' }}</p>
            <h1 id="playlist-editing-heading" class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">{{ $playlist->name ?? 'Playlista — dane wymagają odświeżenia' }}</h1>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-ash-grey-200/70">Nazwa, opis i dane źródłowe są tylko do odczytu. Zmieniasz wyłącznie kolejność lub obecność zapisanych pozycji.</p>
            <a href="{{ $playlist->canonical_source_url }}" rel="noreferrer noopener" class="auth-link mt-5 inline-block">Otwórz źródło</a>
        </div>

        <livewire:playlist-editor :playlist-id="(string) $playlist->getKey()" />
    </section>
@endsection
