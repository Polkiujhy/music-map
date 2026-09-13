<!-- IMPL-REVIEW-REPORT -->
# Przegląd implementacji: Gotowość dostępu do Spotify i YouTube

- **Plan**: context/changes/platform-access-readiness/plan.md
- **Zakres**: Faza 1 z 3
- **Data**: 2026-09-13
- **Werdykt**: APPROVED po triage
- **Ustalenia**: 0 krytycznych, 3 ostrzeżenia, 2 obserwacje

## Werdykty

| Wymiar | Werdykt |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Ustalenia

### F1 — Limit 16 KiB odrzuca poprawny token wielobajtowy

- **Ważność**: ⚠️ WARNING
- **Wpływ**: 🏃 NISKI — szybka decyzja; poprawka jest oczywista i wąsko zakrojona
- **Wymiar**: Plan Adherence
- **Lokalizacja**: app/Integrations/PlatformAccess/RefreshTokenRotationSink.php:30
- **Szczegóły**: Kontrakt dopuszcza replacement refresh token długości 1–8192 znaków UTF-8, a `isSecretString()` mierzy znaki. Sink nakłada dodatkowy limit 16 KiB na cały zakodowany dokument JSON. Poprawny 8192-znakowy token wielobajtowy przechodzi walidację kontraktu, lecz kończy się `refresh-token-rotation-required`.
- **Naprawa**: Usuń dodatkowy limit 16 KiB; walidacja 8192 znaków już ogranicza wejście, a `json_encode()` tworzy skończony dokument.
- **Decyzja**: FIXED — zastąpiono nieuzgodnione 16 KiB opublikowanym limitem PaaS 128 KiB; zgodnie z decyzją użytkownika bez nowego testu regresyjnego.

### F2 — Plik sesji jest czytany bez ograniczenia rozmiaru

- **Ważność**: ⚠️ WARNING
- **Wpływ**: 🔎 ŚREDNI — prawdziwy kompromis; zatrzymaj się, aby to przemyśleć
- **Wymiar**: Safety & Quality
- **Lokalizacja**: app/Integrations/PlatformAccess/TesterSession.php:33
- **Szczegóły**: `file_get_contents()` wczytuje cały dokument z zewnętrznej granicy PaaS przed walidacją pól. Nadmiernie duży lub uszkodzony plik może wyczerpać pamięć przed zwróceniem zamkniętego `invalid-session`. Ograniczenie głębokości `json_decode()` nie ogranicza liczby bajtów.
- **Naprawa ⭐ Zalecana**: Ustal w publicznym kontrakcie v1 maksymalny rozmiar dokumentu sesji i wykonuj ograniczony odczyt do limitu + 1 bajt, mapując przepełnienie na `invalid-session`.
  - **Siła**: Usuwa nieograniczoną alokację na granicy plikowej i zachowuje deterministyczne fail-closed.
  - **Kompromis**: Wymaga skoordynowania nowego, obserwowalnego limitu z konsumentem PaaS przed pierwszą akceptacją v1.
  - **Pewność**: HIGH — odczyt jest obecnie bezwarunkowo nieograniczony, a kontrakt nie definiuje limitu dokumentu.
  - **Martwy punkt**: Nie ma niezależnego, opublikowanego artefaktu PaaS potwierdzającego akceptowalną wartość limitu.
- **Decyzja**: FIXED — zapisano publiczny limit 512 KiB, dodano ograniczony odczyt do limitu + 1 bajt i test obu granic.

### F3 — Testy wejścia nie dowodzą wszystkich deklarowanych granic

- **Ważność**: ⚠️ WARNING
- **Wpływ**: 🏃 NISKI — szybka decyzja; poprawka jest oczywista i wąsko zakrojona
- **Wymiar**: Success Criteria
- **Lokalizacja**: tests/Unit/Integrations/PlatformAccess/ProbeInputTest.php:74
- **Szczegóły**: Kod walidatorów implementuje zasadnicze reguły, lecz kryterium 1.1 oznaczono jako wykonane bez testu brakującego/nieczytelnego pliku `TesterSession::fromFile()`, granic długości wszystkich klas pól, negatywnego formatu YouTube oraz konfiguracji YouTube z exact scope. `PrincipalBoundaryTest.php:14` także nie udostępnia poprawnej alternatywnej sesji, więc nie dowodzi braku fallbacku po pojawieniu się orkiestratora.
- **Naprawa**: Dodaj brakujące przypadki brzegowe fazy 1, a dowód braku fallbacku na punkcie wyboru źródła uzupełnij najpóźniej w fazie 3.
- **Decyzja**: FIXED — dodano możliwe w fazie 1 przypadki graniczne wejścia; dowód braku fallbacku na punkcie orkiestracji zapisano jako follow-up fazy 3.

### F4 — Konfiguracja publikuje dwa nieużywane locatory

- **Ważność**: 👁 OBSERVATION
- **Wpływ**: 🏃 NISKI — szybka decyzja; poprawka jest oczywista i wąsko zakrojona
- **Wymiar**: Scope Discipline
- **Lokalizacja**: config/services.php:45
- **Szczegóły**: `session_path` i `replacement_path` nie były wymagane w tej zmianie konfiguracji i nie są odczytywane. Implementacja korzysta ze stałych `PlatformAccessProtocol::SESSION_PATH` i `REPLACEMENT_PATH`, więc konfiguracja sugeruje możliwość nadpisania, której faktycznie nie zapewnia.
- **Naprawa**: Usuń nieużywane klucze `session_path` i `replacement_path` z `config/services.php`, pozostawiając jedno źródło prawdy w stałych protokołu.
- **Decyzja**: FIXED — usunięto nieużywane klucze locatorów; stałe protokołu pozostają jedynym źródłem prawdy.

### F5 — Ręczna zgodność z publikacją PaaS pozostaje niepotwierdzona

- **Ważność**: 👁 OBSERVATION
- **Wpływ**: 🔎 ŚREDNI — prawdziwy kompromis; zatrzymaj się, aby to przemyśleć
- **Wymiar**: Success Criteria
- **Lokalizacja**: context/changes/platform-access-readiness/plan.md:674
- **Szczegóły**: Punkt 1.4 jest poprawnie niezaznaczony. W repozytorium nie ma niezależnego, opublikowanego kontraktu konsumenckiego PaaS, z którym można porównać invocation, wejścia, odpowiedzi, scope'y, capabilities, kategorie i exit codes. Zgodnie z granicą PaaS przegląd nie rekonstruuje tego kontraktu z prywatnej implementacji Managera.
- **Naprawa**: Udostępnij lub wskaż opublikowany kontrakt `music-map.platform-access.v1`, wykonaj literalne porównanie i dopiero wtedy zaznacz 1.4.
  - **Siła**: Zapewnia niezależny dowód interoperacyjności bez naruszania granicy repozytoriów.
  - **Kompromis**: Wymaga koordynacji z właścicielem publikacji PaaS.
  - **Pewność**: HIGH — wyszukiwanie repozytorium znalazło kontrakt tylko w dokumentach tej zmiany.
  - **Martwy punkt**: Publiczny artefakt może istnieć poza tym checkoutem, ale nie został wskazany.
- **Decyzja**: ACCEPTED — użytkownik wykonuje weryfikację ręcznie; punkt 1.4 pozostaje niezaznaczony do czasu jej zakończenia.

## Dowody weryfikacji automatycznej

| Polecenie | Wynik | Wyjście |
|-----------|-------|---------|
| `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeInputTest.php` | PASS | 36 testów, 99 asercji |
| `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeOutputTest.php` | PASS | 19 testów, 162 asercje |
| `php artisan test tests/Unit/Integrations/PlatformAccess/PrincipalBoundaryTest.php` | PASS | 2 testy, 6 asercji |
| `php artisan test tests/Unit/Integrations/PlatformAccess/RefreshTokenRotationSinkTest.php` | PASS | 4 testy, 16 asercji |

## Weryfikacja ręczna

- `1.4 Potwierdzić zgodność lokalnego kontraktu z publikacją PaaS`: PENDING — brak wskazanego, niezależnego artefaktu publikacji.

## Podsumowanie triage

- **Naprawione**: F1, F2, F3, F4 (4)
- **Zaakceptowane**: F5 — ręczna weryfikacja po stronie użytkownika (1)
- **Pominięte**: brak
