# Synchronizacja playlisty źródłowej — krótki plan

> Pełny plan: `context/changes/source-playlist-sync/plan.md`

## Co i dlaczego

Budujemy ręczną i automatyczną synchronizację banku z własnym źródłem Spotify lub
YouTube, z regułą „źródło wygrywa”, zgodą i globalnym dopuszczeniem zapisów YouTube.

## Punkt wyjścia

Bank ma pochodzenie, rewizję, zawartość i znacznik lokalnej edycji; istnieją też
OAuth, kolejka i scheduler, lecz brakuje wspólnej bazy, writerów i UI synchronizacji.

## Pożądany stan końcowy

Użytkownik zatwierdza podgląd pierwszego uzgodnienia, potem może synchronizować
ręcznie i osobno włączyć automat. Tylko zmiana banku powoduje push; każda zmiana
źródła powoduje pull. Awarie i przekroczenie 20 pozycji nie mutują żadnej strony.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Pierwsze włączenie | Podgląd i jawne potwierdzenie | Zapobiega niespodziewanemu nadpisaniu i zapisowi bez zgody. |
| Tryb automatyczny | Osobny, domyślnie wyłączony przełącznik | Pierwszy confirm nie oznacza zgody na późniejsze zapisy. |
| Konflikt po aktywacji | Cichy source-wins pull | Zachowuje prostą, deterministyczną regułę PRD. |
| Źródło >20 | Wstrzymanie bez zmian | Bank nie udaje częściowej kopii źródła. |
| Brak dostępu/źródła | Zachowanie banku i wstrzymanie | Awaria zewnętrzna nie usuwa prywatnych danych. |
| Ręczny start | Zadanie kolejki | Żądanie UI nie czeka na wieloetapowe API. |
| Częściowy zapis | Uzgodnienie i retry pod tym samym ID | Bezpiecznie domyka YouTube bez drugiego admission. |
| Harmonogram | Due time ≤4h oraz check po loginie ≥15 min | Spełnia PRD i rozkłada obciążenie API. |
| Odłączenie konta | Wyłączenie i ponowny preview/confirm | Reconnect nie wznawia zapisów bez zgody. |
| Historia | Bieżący status i ograniczone runy techniczne | Wystarcza do retry bez budowania produktu audytowego. |
| Spotify public/private | Oba typy po rozszerzeniu scopes | Własne źródła działają jednolicie. |
| Istniejące importy | Weryfikacja właściciela przy aktywacji | Nie ufamy historycznym owner ID ani nie robimy masowego backfillu. |
| Testy live | Deterministyczne fake'i + ręczny smoke | CI jest stabilne, a prawdziwe OAuth i zapisy nadal są weryfikowane. |

## Zakres

**W zakresie:** własne źródła Spotify i YouTube; uwierzytelniony odczyt;
preview/confirm; manual i auto; no-op/pull/push/source-wins; trwałe retry;
YouTube admission; bieżący status; scheduler/login/unlink; testy SQLite,
PostgreSQL i ręczne smoke testy.

**Poza zakresem:** playlisty współdzielone; źródła >20; synchronizacja kopii
eksportowych i drift S-09; historia użytkownika; masowy backfill; inne platformy;
automatyczne testy live w CI; wewnętrzne szczegóły s-manager.

## Architektura / Podejście

`PlaylistSynchronization` przechowuje bazę i cykl życia, a `PlaylistSyncRun`
tożsamość oraz checkpoint retry. Koordynator deleguje do replace Spotify albo
minimalnego diffu YouTube; HTTP działa poza transakcją, a stan jest rewalidowany
pod blokadą.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Model i baza | Stan sync, runy, fingerprint i macierz kierunku | Niewystarczający checkpoint utrudni bezpieczny retry. |
| 2. Odczyt i aktywacja | Własność OAuth oraz preview/confirm | Historyczne owner ID nie mogą kwalifikować collaboratorów. |
| 3. Koordynator i writerzy | Pull, Spotify replace i YouTube diff | Częściowy zapis może zderzyć się z nową zmianą źródła. |
| 4. Automatyzacja | Joby, admission, scheduler/login i unlink | Podwójny dispatch lub admission naruszy limity. |
| 5. UI i stany | Sterowanie, wynik i odzyskiwanie | UI nie może sugerować sukcesu zadania oczekującego. |
| 6. Weryfikacja | Wyścigi, awarie, dokumentacja i smoke | Prawdziwe API może różnić się od atrap kontraktowych. |

**Wymagania wstępne:** zakończone F-02, S-03 i S-04 oraz kontrolowane konta obu dostawców do smoke testów. **Szacowany wysiłek:** około 6 sesji implementacyjnych w 6 fazach.

## Otwarte ryzyka i założenia

- API dostawcy nie zapewnia wspólnej transakcji z bankiem; bezpieczeństwo opiera się na fingerprintach, checkpointach, rewalidacji i natychmiastowym post-read.
- YouTube ordering musi pozwalać na ręczne pozycjonowanie elementów; nieobsługiwany typ playlisty kończy się bez mutacji stanem wymagającym uwagi.
- Stare konto Spotify może wymagać ponownej zgody OAuth na `playlist-modify-public`.

## Kryteria sukcesu (podsumowanie)

- Własna playlista do 20 pozycji poprawnie wykonuje manual i auto no-op/pull/push, a konflikt zawsze kończy się stanem źródła w banku.
- Żadna odmowa, pull ani no-op nie zużywa admission; częściowy YouTube push używa jednego stabilnego operation ID aż do rozstrzygnięcia.
- Unlink, brak źródła i over-limit zachowują bank, zatrzymują automat i nie ujawniają tokenów ani danych dostawcy.
