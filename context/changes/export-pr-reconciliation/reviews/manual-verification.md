# Dowody lokalnej weryfikacji scalonego kandydata

Data: 2026-09-14 (UTC). Kandydat: `13e88091245583aec25522787b0f67fcb5c4a388`.
Weryfikator: agent, na wyraźne polecenie użytkownika samodzielnego sprawdzenia
i odhaczenia potwierdzonych kontroli. Wariant: lokalny przegląd kodu i testy
na izolowanej bazie SQLite, bez wdrożenia oraz zapisów u providerów.
Nie jest to potwierdzenie ręcznego odbioru przez człowieka ani live smoke.
Stan wykonania pozostaje wyłącznie w `../plan.md`, sekcja `Progress`.

## Wykonane sprawdzenia

Uruchomiono:

```sh
php artisan test tests/Feature/ManagedAccountExport tests/Feature/PlaylistSync tests/Unit/Integrations/ManagedAccountExport tests/Unit/Integrations/PlaylistSync
composer validate --strict --no-interaction
sh scripts/verify-source-contract --worktree
```

Wynik suite: 247 passed, 10 PostgreSQL-only skipped, 1457 assertions.
Walidacja Composer i source contract: PASS. Pominięcia nie są dowodem
wykonania testów współbieżności PostgreSQL.

## 5.1 — dowód zaliczenia

Przejrzano migracje eksportu, modele `PlaylistExport`,
`PlaylistExportTargetAttempt`, `ExportOperation` oraz akcje startu,
materializacji i jawnego odtworzenia celu.

- Unikalność źródło/provider/konto zabezpiecza jeden logiczny eksport.
  Snapshot celu jest odrębny od źródła, a przypisanie lokalnego celu jest trwałe.
- Restrictive FK zachowują źródło, cel i historię operacji/prób. Odpięcie konta
  nie usuwa domenowej tożsamości celu ani historii; relacja konta może być null.
- Historia generacji i niezmienne locatory chronią także wcześniejsze cele.
  Guardy źródła blokują import, edycję, review i sync celu eksportowego.
- Materializacja odmawia przejęcia niezależnego importu, także tego samego
  właściciela. Kontrola właściciela i atomowy zapis chronią osobny snapshot.
- Zachowane relacje i historia umożliwiają przyszłe S-09/S-10; nie oznacza to
  implementacji tych funkcji ani zgody na kaskadowe usuwanie danych.

Dowody wykonywalne z powyższej suite:
`ManagedExportMigrationTest`, `ManagedExportModelTest`,
`ManagedExportMaterializationTest`, `ExportTargetBoundaryTest`,
`DurableExportTargetImportTest` i `StartManagedExportTest`.
Przegląd struktury oraz przejście tych testów pokrywają kryteria 5.1.

## Potwierdzenia użytkownika na live — późniejsza sesja

Wydanie zgłoszone przez użytkownika: `mm-85bcce63831b-34897132210-1`,
commit `85bcce63831b7c1a476b334c336896e262f919f0`.
Dokładny czas UTC poszczególnych czynności nie został podany.
Źródło dowodów: relacja użytkownika, nie bezpośrednia obserwacja agenta.

- PASS: bank otwiera się; import Spotify obejmuje 20 pozycji.
- PASS: zapis lokalnej kolejności przetrwał odświeżenie bez zmiany Spotify.
- PASS: podgląd pierwszego sync pokazuje bank → źródło i liczby 20/20;
  potwierdzenie trafiło do kolejki, zakończyło się sukcesem i zmieniło Spotify.
- PASS: ręczny sync pobrał zmianę kolejności Spotify do banku.
- PASS: konflikt aktywnego sync przywrócił wersję źródła bez jej nadpisania.
- PASS: użytkownik wyłączył automat; przycisk zmienił się na włączenie,
  a ostrzeżenie w odświeżonym review eksportu zniknęło.
- PASS: linked YouTube review pozwolił wybrać 19 pozycji, pozostawiając
  jedną poza eksportem i zero usunięć z banku.
- FAIL: linked YouTube create utworzył pusty cel dostępny przez zapisany link,
  ale pierwszy odczyt został sklasyfikowany jako target-missing i operacja
  przeszła do recreate_required. Nie potwierdzono odtworzenia ani cleanup.

Przekazane potwierdzenie operatora obejmuje zdrowe role, addytywne migracje,
CI, backup/restore oraz publiczny kontrakt managed write i trwałej rotacji
przed wydaniem access tokenu. Operator nie wykonywał nowej rotacji.
To uzupełnia 5.3–5.5/5.18, ale nie zastępuje managed live smoke.
Obserwacje użytkownika uzupełniają 5.9–5.14; żaden z tych złożonych punktów
nie ma jeszcze potwierdzonych wszystkich wariantów. Tabela poniżej zachowuje
historyczny stan wcześniejszego, lokalnego przeglądu, nie stan tej sesji live.

Diagnoza operatora: locator zapisany, awaria przed metadanymi/elementami;
brak możliwości odróżnienia HTTP 404 od pustej poprawnej odpowiedzi.
Chwilowa niewidoczność świeżego celu w API jest hipotezą, nie dowiedzioną
przyczyną. Lokalna poprawka daje świeżo utworzonemu celowi YouTube
pięciominutowe okno tolerancji na target-missing przed pierwszym snapshotem,
retry po 60 s i istniejący
limit trzech automatycznych prób. Zachowuje locator, marker i operation ID.
Nie zmienia operacji już zapisanych na live jako recreate_required;
ich odzyskanie wymaga osobnego, zatwierdzonego działania po wdrożeniu.

## Pozostałe bramki — granice wcześniejszego lokalnego dowodu

Poniższe obserwacje nie zastępują odhaczeń ani pełnego odbioru podprzypadków.

| Bramka | Wynik dowodowy i brakujące potwierdzenie |
| --- | --- |
| 5.2 | Częściowy: przegląd modeli, jobów, checkpointów i granic dostępu; brak kontroli rzeczywistych failed jobs i logów runtime. |
| 5.3 | Niepotwierdzona: brak operatorowego potwierdzenia produkcyjnej rotacji oraz smoke zwykłego workera. |
| 5.4 | Częściowy: harmonogramy i joby obsługują sync/recovery w kodzie; nie uruchomiono wdrożonych ról queue/scheduler. |
| 5.5 | Niewykonana publikacja: testy migracji nie zastępują upoważnionego cutover i kontroli schematu wydania. |
| 5.6 | Częściowy: testy recovery i niepełnego scan; brak round-trip markerów i pełnego scan realnych kont obu providerów/trybów. |
| 5.7 | Częściowy: testy exact order/occurrences i odmowy; brak potwierdzenia rzeczywistego zachowania YouTube. |
| 5.8 | Częściowy: sprawdzono sekwencje i retry opisane poniżej; pozostaje luka dowodu przerwania oraz rozbieżność copy FR-011. |
| 5.9 | Częściowy: testy kwalifikacji i niezmienności aktywnego sync przy preview; brak pełnego przejścia public/private Spotify z reconnect. |
| 5.10 | Częściowy: testy renderowania/statusów/pollingu; brak desktop/mobile, czytnika, klawiatury, zoom 200% i dwóch kart. Rozbieżność copy poniżej. |
| 5.11 | Częściowy: testy powiadomień nie zastępują rzeczywistej dostawy po opuszczeniu operacji trwającej co najmniej 60 s w obu trybach. |
| 5.12 | Częściowy: testy granic, source-wins i utraty dostępu; brak pełnego odbioru obu providerów z rzeczywistym reconnect. |
| 5.13 | Niewykonany live smoke Spotify. |
| 5.14 | Niewykonany live smoke YouTube i kontrolowany live retry F-02. |
| 5.15 | Częściowy: testy partial retry, identity, recreate i admission; brak pełnej macierzy awarii integracji obu providerów/trybów. |
| 5.16 | Niewykonany cleanup smoke: nie tworzono zewnętrznych zasobów, ale nie zalicza to cleanup przyszłego smoke. |
| 5.17 | Niewykonana kontrola kompletu artefaktów smoke, gdyż smoke nie odbył się. Ten raport nie zawiera tokenów, payloadów ani locatorów providerów. |
| 5.18 | Niepełny odbiór: otwarte wcześniejsze bramki, brak opublikowanego schematu i live potwierdzenia rotacji; same testy kandydata nie zamykają wydania. |

## 5.8 — wykonany przegląd i otwarte ograniczenia

Przejrzano start, publikację, recovery, rozwiązywanie celu, runner,
materializację i jawne recreate. `RunManagedExportTest`,
`ManagedExportWorkflowTest`, `ManagedExportRecoveryTest` oraz testy
materializacji potwierdzają m.in. odzyskanie utraconego dispatch,
admission przed dostępem/zapisem, nieponawianie nieznanego create,
checkpoint locatora, retry częściowego wyniku i blokadę stale generation.
Zwykłe retry zachowuje logiczną operację, cel i admission;
jawne recreate tworzy nową generację z zachowaniem historii.
Przegląd kodu potwierdza krótkie transakcje stanu oddzielone od HTTP.

Nie zebrano osobnego, wykonanego dowodu kontrolowanego przerwania dokładnie
między remote success a local commit. Przegląd kodu nie jest opisany jako
wykonany fault injection.

Ponadto PRD FR-011 podaje `Przeniesiona — na Twoim koncie`, natomiast
`app/Livewire/ExportReviewPanel.php` zwraca
`Przeniesiona — na Twoje połączone konto`. To rozbieżność brzmienia,
nie dowód błędnego wyboru właściciela. Wymaga uzgodnienia lub poprawki copy
przed pełnym zaliczeniem 5.8/5.10; nie zmieniano produktu w ramach tej kontroli.

## Zakres pozostawionych zmian

Aktualizacja: użytkownik następnie zgodził się na commit/push i nowy PR
z poprawką oraz tym raportem. Bez merge, timera, deploy i prośby o review.
Pełna regresja poprawki: 757 passed, 21 PostgreSQL-only skipped,
4528 assertions; Pint i source contract PASS. Poniższy zapis dotyczy
wcześniejszego etapu przed tą zgodą.

Zmieniono lokalnie dokumentację dowodów, notatkę zmiany i odhaczenie 5.1.
Nie wykonano commit, push, merge, deploy ani prośby o nowy review.
Status zmiany pozostaje `implementing`; historyczne plany i roadmapa
nie zostały oznaczone jako zakończone.
