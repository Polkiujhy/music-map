@extends('layouts.app')

@section('title', 'Twój bank')

@section('content')
    <section aria-labelledby="bank-heading">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Prywatna przestrzeń</p>
        <div class="mt-3 flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <h1 id="bank-heading" class="text-4xl font-semibold tracking-tight sm:text-5xl">Twój bank playlist</h1>
                <p class="mt-3 max-w-2xl text-lg leading-8 text-ash-grey-200/70">Witaj, {{ auth()->user()->name }}. To tutaj powstanie Twoja niezależna kolekcja muzyki.</p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-ash-grey-900 px-3 py-1.5 text-sm font-semibold text-ash-grey-200">
                <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-4" stroke="currentColor" stroke-width="1.8">
                    <rect x="4.5" y="8.5" width="11" height="8" rx="2" />
                    <path d="M7 8.5V6a3 3 0 0 1 6 0v2.5" />
                </svg>
                Widoczne tylko dla Ciebie
            </span>
        </div>

        <div class="mt-10 overflow-hidden rounded-3xl border border-ash-grey-900 bg-[#1d211e] shadow-2xl shadow-black/20">
            <div class="grid min-h-96 place-items-center px-6 py-16 text-center sm:px-12">
                <div class="max-w-lg">
                    <div aria-hidden="true" class="mx-auto flex size-20 items-center justify-center rounded-3xl bg-ash-grey-900 text-4xl text-ash-grey-300">♫</div>
                    <h2 class="mt-7 text-2xl font-semibold tracking-tight">Bank czeka na pierwszą playlistę</h2>
                    <p class="mt-3 leading-7 text-ash-grey-200/70">Każda zapisana tu playlista będzie prywatna i dostępna wyłącznie z Twojego konta.</p>
                    <p class="mt-4 rounded-xl bg-[#171717] px-4 py-3 text-sm text-ash-grey-300">Import playlist uruchomimy w kolejnym etapie. Nie musisz jeszcze nic konfigurować.</p>
                </div>
            </div>
        </div>
    </section>
@endsection
