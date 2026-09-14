<x-mail::message>
# Przegląd eksportu jest zakończony

Platforma docelowa: {{ $platform }}

@if ($successful)
Wynik jest gotowy do bezpiecznego sprawdzenia w Music Map.
@else
Nie udało się przygotować wyniku. W Music Map znajdziesz bezpieczne informacje o dalszych krokach.
@endif

<x-mail::button :url="$url">
Otwórz przegląd eksportu
</x-mail::button>

Pozdrawiamy,<br>
{{ config('app.name') }}
</x-mail::message>
