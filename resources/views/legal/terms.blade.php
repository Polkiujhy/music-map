@extends('layouts.app')

@section('title', 'Warunki korzystania')

@section('content')
    <article class="mx-auto max-w-3xl">
        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-ash-grey-400">Music Map</p>
        <h1 class="mt-3 text-4xl font-semibold tracking-tight">Warunki korzystania</h1>
        <div class="mt-8 space-y-6 text-base leading-7 text-ash-grey-200/80">
            <p>Music Map pozwala zapisać prywatną kopię metadanych playlisty w banku należącym do zalogowanego użytkownika. Użytkownik odpowiada za linki przekazywane do importu oraz zgodne z prawem korzystanie z zapisanych danych.</p>
            <p>Korzystanie z funkcji opartych na YouTube podlega również <a href="https://www.youtube.com/t/terms" rel="noreferrer noopener" class="auth-link">Warunkom korzystania z YouTube</a>.</p>
            <p>Usługa może odmówić importu niedostępnej playlisty, nieobsługiwanych pozycji lub playlisty przekraczającej limit produktu. Music Map nie przechowuje danych logowania do YouTube w ramach publicznego importu.</p>
        </div>
    </article>
@endsection
