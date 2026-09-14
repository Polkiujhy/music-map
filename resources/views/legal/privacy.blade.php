@extends('layouts.app')

@section('title', 'Polityka prywatności')

@section('content')
    <article class="mx-auto max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Music Map</p>
        <h1 class="mt-3 text-4xl font-semibold tracking-tight">Polityka prywatności</h1>
        <div class="mt-8 space-y-6 text-base leading-7 text-ash-grey-200/80">
            <p>Music Map korzysta z YouTube API Services, aby na żądanie użytkownika odczytać publiczną playlistę. Uzyskujemy identyfikator i kanoniczny adres playlisty, jej nazwę i opis, identyfikator właściciela, informacje o odświeżeniu oraz maksymalnie 20 uporządkowanych pozycji.</p>
            <p>Dane playlisty zapisujemy w prywatnym banku użytkownika. Okresowo je odświeżamy, a gdy nie można potwierdzić ich aktualności, usuwamy metadane pochodzące z YouTube albo przestajemy przedstawiać je jako aktualne.</p>
            <p>Publiczny import nie przekazuje Music Map hasła ani tokenu konta YouTube. Szczegóły przetwarzania przez Google opisuje <a href="https://policies.google.com/privacy" rel="noreferrer noopener" class="auth-link">Polityka prywatności Google</a>. Dostęp aplikacji powiązanych z kontem można sprawdzić w <a href="https://myaccount.google.com/permissions" rel="noreferrer noopener" class="auth-link">ustawieniach zabezpieczeń Google</a>.</p>
        </div>
    </article>
@endsection
