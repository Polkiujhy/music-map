<x-mail::message>
# Eksport playlisty jest zakończony

Platforma docelowa: {{ $platform }}

@if ($successful)
Playlista została przeniesiona — {{ $owner }}.
@else
Nie udało się przenieść playlisty. W music-map znajdziesz bezpieczną przyczynę i dalsze kroki.
@endif

<x-mail::button :url="$url">
Otwórz wynik eksportu
</x-mail::button>

Pozdrawiamy,<br>
{{ config('app.name') }}
</x-mail::message>
