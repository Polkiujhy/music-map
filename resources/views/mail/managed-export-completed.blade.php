<x-mail::message>
# Przenoszenie playlisty jest zakończone

Platforma docelowa: {{ $platform }}

Status: {{ $statusLabel }}

@if ($successful)
{{ $linked ? 'Kopia należy do Twojego połączonego konta.' : 'Kopia należy do konta zarządzanego przez music-map.' }} Otwórz status, aby przejść do bezpiecznie zapisanego linku.
@else
W Music Map znajdziesz bezpieczną przyczynę oraz dostępne dalsze kroki. Wiadomość nie zawiera danych technicznego konta ani sekretów.
@endif

<x-mail::button :url="$url">
Otwórz status przenoszenia
</x-mail::button>

Pozdrawiamy,<br>
{{ config('app.name') }}
</x-mail::message>
