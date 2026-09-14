---
date: 2026-09-14T20:50:00+01:00
researcher: Codex
git_commit: d497a274abbead0af0057b92541f1e70aed4a7cf
branch: test/ambiguous-outcomes-safe-retry
repository: Polkiujhy/music-map
topic: "Pogodzenie PR 59, 61 i 62 oraz zabezpieczenie przed regresjami"
tags: [research, codebase, export, source-sync, recovery, integration, regression]
status: complete
last_updated: 2026-09-14
last_updated_by: Codex
last_updated_note: "Rozszerzono o PR 59, nowsze rewizje i zależności sync–export"
---

# Research: pogodzenie PR 59, 61 i 62

## Research Question

Jak wykorzystać pracę z obu PR-ów bez kolizji oraz utraty zachowania? Użytkownik
oczekuje propozycji, nie wdrożenia. Merge wyłącznie po jego wyraźnej zgodzie.

## Summary

To dwie konkurencyjne implementacje eksportu na konto techniczne. #62 dodatkowo
obsługuje konta użytkowników. Prosty merge albo zmiana nazw klas nie rozwiązuje
problemu: pozostałyby dwa mechanizmy zarządzające tą samą zewnętrzną playlistą.

Rekomendacja: oprzeć wspólny lifecycle i historię celu na #61, uogólnić je dla
linked/managed i przenieść z #62 linked access, zamrożenie metadanych, ścisłe
granice source/target oraz oszczędniejszą aktualizację YouTube. Zachować jeden
model operacji, relacji i generacji celu, jeden dispatcher i jeden zestaw statusów.
To rekomendacja do uzgodnienia, nie zatwierdzony plan implementacji.

Po rozszerzeniu o #59: zachować osobny lifecycle synchronizacji źródła i wspólny
lifecycle eksportu #61/#62. Łączyć kontrakt ról playlist, kontrolę dostępu i globalny
limit YouTube. Source-wins dla sync i zamrożony manifest eksportu są różnymi
regułami produktu. Szczegóły i aktualne rewizje w końcowej sekcji Follow-up.
Poniższe pierwotne ustalenia/CI dla #61/#62 dotyczą jawnie wymienionych commitów.

## Compared Revisions

| Element | Rewizja |
| --- | --- |
| PR #61 | `ab74f533d8f97cec33354cd4d801669f011a78d6` |
| PR #62 | `922e7d9cabef5ca580e90e9d7b27c5af1005929f` |
| Wspólny przodek | `e529135e2a7434e4519e4f8cb96bd9fc9c9a836c` |
| Odczytane origin/main | `247e95b` |

#61 zmienia 100 plików, #62 99; 27 ścieżek jest wspólnych. `git merge-tree`
wykazał 20 konfliktujących ścieżek między PR-ami. Każdy PR osobno łączy się
tekstowo z odczytanym main bez konfliktów. Main zawiera już dodatkowe testy
niejednoznacznych wyników i safe retry, które trzeba uwzględnić przy integracji.
Symulacja merge-tree nie zmieniła gałęzi ani plików roboczych.

## Detailed Findings

### 1. Schemat i odpowiedzialność za eksport

Obie migracje wykonują `Schema::create('export_operations')`. W #61 kluczem
głównym jest UUID i relacja `playlist_export_id`; w #62 bigint, dodatkowe UUID
`operation_id` i `playlist_export_link_id`. Różnią się też statusy i zależności
usuwania. Git nie zgłasza konfliktu migracji, bo mają różne nazwy plików.

- [#61 migracja](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/database/migrations/2026_09_14_000700_create_export_operations_table.php#L11)
- [#62 migracja](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/database/migrations/2026_09_14_010200_create_export_operations_table.php#L11)

Zachować jeden schemat. Nie stosować obejścia `hasTable`, bo ukryłoby niezgodność
kolumn. Przed implementacją ustalić, czy którakolwiek wersja schematu została już
wdrożona: jeśli tak, potrzebna będzie migracja danych; nie sprawdzano runtime.

### 2. Trwałe retry i historia celu — zachować z #61

#61 rozróżnia generację ręcznego retry i konkretne wykonanie, utrwala limit
claimów oraz dzierżawę publikacji joba. #62 przekazuje jobowi tylko operation ID,
a ręczne retry zeruje attempt_count. Starszy payload nie niesie informacji,
że pochodzi z wcześniejszej generacji. Ochrona przed overlap nie zastępuje tej
kontroli. Przy integracji weryfikować generację także przed mutacją providera.

- [#61 claim](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ManagedAccountExport/RunManagedExport.php#L100)
- [#61 publisher](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ManagedAccountExport/PublishManagedExport.php#L24)
- [#62 retry](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Actions/Exports/RetryExportOperation.php#L33)
- [#62 payload](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Jobs/ExecuteExportOperation.php#L31)

#61 zachowuje osobny rekord każdej generacji markera i provider ID przy jawnym
recreate. Ten kontrakt warto zachować również dla linked. Locator częściowej
kopii zapisać od razu, a docelową Playlist materializować dopiero po zweryfikowanym
sukcesie, jak #61. UI może wcześniej pokazywać status i link operacji.

- [Historia celu #61](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Models/PlaylistExportTargetAttempt.php#L44)
- [Recreate #61](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ManagedAccountExport/RequestManagedTargetRecreation.php#L45)
- [Materializacja #61](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ManagedAccountExport/MaterializeManagedExportPlaylist.php#L92)
- [Wczesna materializacja #62](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Actions/Exports/PersistExportTarget.php#L67)

### 3. Konta użytkowników i frozen input — zachować z #62

#62 wiąże operację z konkretną tożsamością konta i kontroluje credential_version
przed mutacjami. To potrzebne zachowanie przy unlink/relink i zmianie uprawnień.
Zamraża również tytuł i opis; #61 odczytuje aktualne metadane źródła przy retry.
Wspólna operacja powinna używać tego samego zatwierdzonego wejścia przez cały czas.

- [Linked access](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Integrations/PlaylistExport/Actions/WithLinkedExportAccess.php#L26)
- [Mutation guard](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Integrations/PlaylistExport/Actions/LinkedExportMutationGuard.php#L20)
- [Frozen metadata #62](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Actions/Exports/StartExportOperation.php#L75)
- [Mutable metadata #61](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ManagedAccountExport/ReconcileManagedExport.php#L37)

Zachować także odmowę uruchamiania nowego eksportu przez replay historycznego
confirmed review bez operacji: #62 wymaga nowego review; #61 tworzy operację.

- [#62 historyczny confirm](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Actions/ExportReviews/ConfirmExportReview.php#L42)
- [#61 historyczny confirm](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Actions/ExportReviews/ConfirmExportReview.php#L41)

### 4. Granice source/target — zachować mocniejsze kontrole #62

#61 ma wczesną odmowę importu znanego celu, ale #62 dodatkowo sprawdza rolę
w transakcji i bezpośrednio w ReplaceImportedPlaylist. To chroni także akcje
wywołane z pominięciem kontrolera i wyścig importu z materializacją celu.
Nie jest to twierdzenie, że zwykły sekwencyjny import celu w #61 zawsze go nadpisuje.

- [#62 kontrola w upsercie](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Actions/Playlists/ReplaceImportedPlaylist.php#L29)
- [#62 testy granicy](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/tests/Feature/Exports/PlaylistExportTargetBoundaryTest.php)

Jedna neutralna rola Source/ExportTarget jest czytelniejsza dla obu typów kont
niż Imported/ManagedTarget. Przy kolizji z już zaimportowaną playlistą rekomenduję
odmowę zamiast automatycznej zmiany jej roli: #61 dopuszcza adopcję zgodnej kopii,
#62 odmawia lokalnej kolizji ID. To decyzja produktowa do zatwierdzenia w planie.

### 5. Providerzy — połączyć konkretne zalety

#61 przy zmianie YouTube usuwa wszystkie pozycje i wstawia je od nowa. #62
zachowuje część wystąpień i przestawia pozycje, ograniczając liczbę mutacji.
Warto zachować algorytm #62, ale z pełną weryfikacją stabilnego stanu po zapisie
z #61, obejmującą metadane, widoczność, kolejność i duplikaty.

- [#61 replacement i verification](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Integrations/ManagedAccountExport/Providers/YouTubeManagedPlaylistGateway.php#L183)
- [#62 minimalne zmiany](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Integrations/PlaylistExport/Providers/YouTubePlaylistWriter.php#L294)

### 6. Publiczny kontrakt kont technicznych

Oba PR-y korzystają z brokera `music-map.managed-export.v1`. Manager pozostaje
zewnętrznym PaaS; nie analizowano jego implementacji ani stanu produkcji.
Z #61 zachować dedykowaną konfigurację tożsamości `services.managed_export.providers`
i jej porównanie z zamrożonym kontem. #62 nadal czyta identyfikator z namespace
`services.platform_access.*.technical`. Nie wprowadzać fallbacku do tokenów probe.
Z #62 zachować kontrolę minimalnej pozostałej ważności tokenu przed pracą.

- [#61 weryfikacja konfiguracji](https://github.com/Polkiujhy/music-map/blob/ab74f533d8f97cec33354cd4d801669f011a78d6/app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php#L25)
- [#62 access i expiry](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/app/Integrations/PlaylistExport/Actions/WithManagedExportAccess.php#L19)

## Verification and CI

- [CI #61](https://github.com/Polkiujhy/music-map/actions/runs/34888268576): application, source-security, postgres-smoke i container-images PASS.
- [CI #62](https://github.com/Polkiujhy/music-map/actions/runs/34888000133): application i source-security PASS; postgres-smoke FAIL, 27 failures, 592 passed; obrazy pominięte.
- W #62 StartExportOperationTest wykonuje `migrate:fresh` w setUp bez RefreshDatabase
  ani cleanup w tearDown. W procesie, który wcześniej ustawił RefreshDatabaseState,
  kolejne testy mogą użyć pozostałej playlisty. Fabryki używają canary ID zgodnych
  z nadmiarowymi rekordami widocznymi w logu CI.
- [Miejsce braku cleanup](https://github.com/Polkiujhy/music-map/blob/922e7d9cabef5ca580e90e9d7b27c5af1005929f/tests/Feature/Exports/StartExportOperationTest.php#L23)

Odtworzenie na niezmienionym kodzie #62, w izolowanym worktree i syntetycznej
plikowej SQLite (polecenia `php artisan test --compact --filter=...`):

| Wybrane testy | Baza | Wynik |
| --- | --- | --- |
| StartExportOperationTest + PlaylistImportTest::test_guest_and_unverified_user_cannot_import | plikowa SQLite | 6/6 PASS |
| ExportOperationDispatchRecoveryTest + StartExportOperationTest + powyższy test importu | plikowa SQLite | 6 PASS, 1 FAIL: playlists oczekiwane 0, rzeczywiste 1 |
| ExportOperationDispatchRecoveryTest + powyższy test importu | plikowa SQLite | 2/2 PASS |
| Te same trzy klasy/filtry co w wierszu z błędem | SQLite :memory: | 7/7 PASS |

To potwierdza zależność od kolejności i trwałości bazy. Mocno wskazuje źródło
kaskady błędów CI, ale nie zastępuje ponownego pełnego PostgreSQL po naprawie.
Naprawa powinna zachować realne testowanie afterCommit i rollback: np. jawny
cleanup po testach wymagających commitów oraz spójny reset stanu migracji.
Nie dodawać bezrefleksyjnie transakcji obejmującej cały test afterCommit.

Lokalny PostgreSQL 15 został uruchomiony wyłącznie do tej diagnozy, ale tworzenie
jednorazowej bazy nie powiodło się z powodu wyczerpanych inode'ów /tmp. Serwer
zatrzymano i usunięto utworzony klaster. Nie uruchomiono na nim testów. CI używa
PostgreSQL 18. Nie uruchamiano rzeczywistych API, E2E ani pełnego zestawu lokalnie.

## Architecture Insights and Proposed Delivery

1. Najpierw uzgodnić jeden kontrakt operacji, statusów, celu i jawnego recreate.
   Zrobić tabelę mapowania zachowań/testów z obu PR-ów; nic istotnego nie może
   zniknąć wyłącznie dlatego, że zmienia się nazwa klasy.
2. Przygotować #61 jako wspólny fundament: historia celu i fencing pozostają;
   nazwy i kontrakty stają się neutralne wobec linked/managed. Włączyć frozen
   metadata i guardy #62 już na tym poziomie.
3. Przebudować #62 na zależne rozszerzenie tego fundamentu: linked access,
   linked UX i testy czterech kombinacji. Usunąć z jego zakresu drugi schemat,
   drugi dispatcher i drugi lifecycle managed. Przenieść minimalny writer
   YouTube z testami w oddzielnym, łatwym do oceny kroku.
4. Zweryfikować wspólną gałąź na aktualnym main, wraz z testami safe retry
   dodanymi już do main. Dopiero potem przedstawić konkretne commity do akceptacji.
5. Merge tylko po wyraźnej zgodzie użytkownika. Analiza nie autoryzuje publikacji.

Alternatywa: jeden nowy PR integracyjny zastępujący oba. Ułatwia ocenę końcowego
wyniku, ale daje większy diff i trudniejszą kontrolę pochodzenia zmian. Preferuję
fundament + zależne rozszerzenie, o ile żadna z wersji nie została już wdrożona.

## Regression Acceptance Matrix

| Ryzyko | Wymagany dowód |
| --- | --- |
| Cztery ścieżki kont | Spotify/YouTube × linked/managed: create, sukces, następny review aktualizuje ten sam ID |
| Zgoda użytkownika | Replay confirm idempotentny; stary confirmed bez operacji wymaga nowego review |
| Nieznany wynik create | Zero/jeden/wiele markerów, scan niepełny, timeout: bez drugiego automatycznego create |
| Przerwanie zapisu | Awaria po create, w środku listy i przed commit DB: retry tego samego celu, dokładny manifest |
| Stare wykonania | Stary job i późny wynik po retry/recreate nie zmieniają nowej generacji; limit claimów nie resetuje się przez recovery |
| Dispatch | Utrata enqueue odzyskiwana; cykliczny scheduler nie namnaża aktywnych dostaw |
| Tożsamość i dostęp | Unlink/relink tego samego i innego konta, rotacja credential, zmiana managed identity, zbyt krótki token |
| YouTube admission | Odmowa przed mutacją; retry zachowuje tę samą logiczną rezerwację także po zmianie dnia |
| Wynik providera | Kolejność, duplikaty, metadata i visibility; błędny wynik mimo HTTP 2xx nie daje sukcesu |
| Ochrona banku | Import/edit/reimport/maintenance oraz wyścig importu z materializacją nie nadpisują eksportów |
| Usunięty cel | Jawna zgoda na nowy link, zachowana historia starego celu i markerów |
| Migracje i współbieżność | Jeden schemat; pełne SQLite i PostgreSQL, równoległe confirm/retry/claim/checkpoint; losowa kolejność testów |

Do adaptacji m.in. #61 ManagedExportWorkflowTest, ManagedExportRecoveryTest,
ManagedExportRouteTest i ManagedExportMaterializationTest oraz #62
PlaylistExportTargetBoundaryTest, ExecuteExportOperationTest i testy admission.
Samo zielone CI każdej starej gałęzi nie dowodzi poprawności połączonego kodu.

## Historical Context and Related Research

- #61 `context/changes/managed-account-export/plan-brief.md`: historia target attempts, exact reconciliation, materializacja po sukcesie.
- #62 `context/changes/linked-account-export/plan-brief.md`: świadomie wspólny zakres S-06 + S-07.
- `context/foundation/prd.md`: FR-009–011, BR-003 i NFR-006 wyznaczają wspólny kontrakt eksportu.
- `context/foundation/lessons.md`: weryfikacja twierdzeń, zakaz push bez akceptacji i granica zewnętrznego PaaS.
- `context/archive/2026-09-14-testing-ambiguous-outcomes-safe-retry/`: wcześniejsze prace nad niejednoznacznymi wynikami; archiwum pozostaje bez zmian.

## Open Questions

- Czy schemat #61 lub #62 jest już używany poza jednorazowymi testami? To zmienia strategię migracji danych; runtime nie był w zakresie tej analizy.
- Docelowy kontrakt kolizji z istniejącym importem: rekomendowana odmowa zamiast automatycznej adopcji.
- Ostateczne nazwy stanów, UI recreate i podział commitów należy ustalić w planie po wyborze kierunku.
- Pełny brak regresji wymaga implementacji oraz weryfikacji połączonego kodu; ta analiza wskazuje potwierdzone kolizje i warunki akceptacji.

## Follow-up Research 2026-09-14T20:56:00+01:00 — dodanie PR 59

### Zakres i aktualne rewizje

Użytkownik polecił dodać PR #59. Nadal jest to analiza i rekomendacje, bez merge,
push, modyfikacji PR-ów ani implementacji w aplikacji. Kontynuowano 10x-research
i tę samą granicę publicznego kontraktu s-manager-use.

| PR | Zbadana aktualna rewizja | Zakres |
| --- | --- | --- |
| #59 | `61de763d5f6e2571b6f342c46daccda5cb60334a` | Synchronizacja własnego źródła z bankiem |
| #61 | `67655190da5daaf6f9883a2a318e161bf32fd29b` | Eksport na konta techniczne |
| #62 | `f40b1c5c08a28f8080ae3e4590569643f60a34e8` | Wspólny eksport linked/managed |

Kod #59 odczytano z `dbf48d2`; późniejszy `61de763` zmienia wyłącznie trzy dokumenty
foundation, co sprawdzono diffem. Odczytane main: `38d6db8`. Najnowsze PRD odkłada
S-09/drift kopii poza MVP — integracja nie powinna przywracać tego zakresu.
#61 od poprzedniej analizy włączył main; #62 zmienił cleanup dwóch klas testów
PostgreSQL, ale nie StartExportOperationTest.

| Para | Konfliktujące ścieżki według merge-tree |
| --- | ---: |
| #59 + #61 | 4 |
| #59 + #62 | 5 |
| #61 + #62 | 20 |

#59/#61: RefreshStaleYouTubePlaylistMetadata, Playlist, routes/console,
verify-source-contract. #59/#62: Playlist, StreamingAccount, bootstrap/providers,
routes/console, verify-source-contract. Liczby dotyczą par, nie sumy unikalnych
konfliktów trzech PR-ów. W #59 powstają osobne playlist_synchronizations i
playlist_sync_runs; nie dodaje on trzeciej tabeli export_operations. Konflikt
dwóch migracji export_operations pozostaje między #61 i #62.

### F1. Synchronizacja musi wykluczać cele eksportu

Controller #59 pobiera dowolną playlistę danego użytkownika. Prepare weryfikuje
właściciela u providera, ale nie rolę Source/ExportTarget. Cel wyeksportowany na
linked account może spełniać oba warunki. Bezpośrednie wywołanie endpointu sync
może więc aktywować drugi mechanizm zapisu tej samej kopii, mimo ukrytego przycisku
edycji w UI eksportu. Dispatch, worker i ApplySourcePlaylistToBank również nie
mają warunku roli. To luka integracyjna; sam #59 nie wprowadza jeszcze targetów.

- [Controller #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Http/Controllers/PlaylistSynchronizationController.php#L149)
- [Prepare #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Actions/PlaylistSync/PreparePlaylistSynchronization.php#L38)
- [Apply source #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Actions/PlaylistSync/ApplySourcePlaylistToBank.php#L14)

Rekomendacja: neutralne role z #62 i wspólny guard Source na prepare/confirm,
dispatch, wykonaniu i zapisie wyniku; filtry scheduler/login. Już zakolejkowany
job też musi odmówić po zmianie roli. Synchronizacja kopii eksportowych pozostaje
poza zakresem. To dodatkowy argument przeciw adopcji istniejącego importu jako
target w #61: import może mieć już aktywną PlaylistSynchronization; zmiana origin
playlisty nie usuwa jej osobnej relacji z kontem.

### F2. Rozszerzenie scopes Spotify nie powinno blokować prywatnego eksportu

#59 dodaje playlist-modify-public do globalnego requiredScopes(). #62 używa
całej tej listy przy wyborze linked destination i przy dostępie do eksportu.
Po połączeniu dotychczasowe konto z prawami do private export zostałoby odrzucone
z powodu nowego prawa potrzebnego synchronizacji publicznego źródła.

- [Globalna lista #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Enums/StreamingProvider.php#L18)
- [Access #62](https://github.com/Polkiujhy/music-map/blob/f40b1c5c08a28f8080ae3e4590569643f60a34e8/app/Integrations/PlaylistExport/Actions/WithLinkedExportAccess.php#L46)
- [Kontrola scopes po refresh](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php#L46)

Rekomendacja: rozdzielić zakres żądany przy OAuth od wymagań konkretnej operacji
(import, private export, source sync). Ponowna zgoda może być wymagana dla nowej
funkcji sync, ale nie ma odbierać istniejącej funkcji eksportu. Przejrzeć wszystkie
wywołania requiredScopes(), w tym linkowanie i walidację grantów. Managed #61
już używa własnych stałych gatewaya, więc nie twierdzimy, że ta zmiana obecnie
automatycznie rozszerza jego wymagania — przy refaktorze zachować rozdzielenie.

### F3. Usuwanie z banku podczas eksportu ma skutek dla źródła

ConfirmExportReview z obu eksportowych PR-ów usuwa wybrane pozycje z banku i
oznacza lokalną edycję. DeterminePlaylistSyncDirection #59 wybiera push, gdy
zmienił się tylko bank. Przy włączonym automacie i niezmienionym źródle usunięcie
z review eksportu może więc później trafić do oryginalnej playlisty.

- [Usuwanie z banku #62](https://github.com/Polkiujhy/music-map/blob/f40b1c5c08a28f8080ae3e4590569643f60a34e8/app/Actions/ExportReviews/ConfirmExportReview.php#L117)
- [Wybór kierunku #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Actions/PlaylistSync/DeterminePlaylistSyncDirection.php#L19)

Jest to konsekwencja FR-008 i FR-005, a nie samoistny dowód błędu. Pokazać skutek
w potwierdzeniu, gdy automat jest aktywny, i sprawdzić testem. Nie zmieniać bez
decyzji produktowej „usuń z banku” na „pomiń tylko w eksporcie”. Eksport nie może
sam włączać synchronizacji ani jej trybu automatycznego.

### F4. Zmiana banku przez sync a zatwierdzony eksport

Przed confirm aktualny fingerprint powinien unieważnić review zmienione przez
sync. Po confirm worker eksportu ma zachować zamrożony manifest, nawet jeżeli
sync zmieni bank. #59 przy pull stosuje source-wins; przy push wykrywa edycję banku
w czasie I/O i może uruchomić kolejny run. Potwierdzenie eksportu jest jedną z
takich edycji i wymaga testów obu kolejności transakcji.

- [Zakończenie runu #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Actions/PlaylistSync/CompletePlaylistSyncRun.php#L39)
- [Drift podczas push #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Actions/PlaylistSync/RunPlaylistSynchronization.php#L160)

Zachować dwa koordynatory: sync uzgadnia istniejące źródło według source-wins;
eksport realizuje zatwierdzony manifest i create/recovery celu. Można później
współdzielić planner mutacji YouTube i transport po testach zgodności; nie jest
konieczne łączenie maszyn stanów ani przebudowa wszystkich writerów od razu.

### F5. Utrzymanie, unlink i limit YouTube są wspólnymi granicami

Refresh/purge YouTube musi jednocześnie wykluczać cele eksportu (#61/#62) oraz
playlisty obsługiwane przez sync (#59), także przy ponownej kontroli w transakcji
i wykonaniu starego joba. Symulacja #59+#62 zachowuje oba filtry automatycznie;
to warunek do utrzymania, nie wykryty błąd jej scalonego query. #59+#61 konfliktuje
w tym pliku, więc wybranie całej jednej wersji byłoby niewystarczające.

- [Filtry utrzymaniowe #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php#L24)
- [Kontrola przy wykonaniu joba](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Jobs/RefreshYouTubePlaylistMetadata.php#L33)

Warto uogólnić guard #62 sprawdzający credential_version przed mutacją również
na source sync. #59 kontroluje stan przy rozpoczęciu i zakończeniu, ale pętla
mutacji YouTube nie sprawdza pomiędzy zapisami, czy użytkownik właśnie odłączył
konto lub wyłączył synchronizację. Stan runu i zgoda workflow powinny wejść do
tej kontroli obok tożsamości konta.

- [Pętla zapisów #59](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistWriter.php#L75)
- [Guard linked #62](https://github.com/Polkiujhy/music-map/blob/f40b1c5c08a28f8080ae3e4590569643f60a34e8/app/Integrations/PlaylistExport/Actions/LinkedExportMutationGuard.php#L20)

Istniejący ledger admission jest globalny, z typami SourceSync/LinkedExport/
ManagedExport i stabilnym ID logicznej operacji. Zachować jeden licznik i tę samą
rezerwację na retry; nowy run dostaje nowe ID. Test przekrojowy powinien uruchomić
trzy typy równocześnie przy jednym pozostałym miejscu. Pull/no-op sync nie zużywa
admission. W produkcyjnym writerze dependency admission ma być obowiązkowa.

- [Admission source sync](https://github.com/Polkiujhy/music-map/blob/61de763d5f6e2571b6f342c46daccda5cb60334a/app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistWriter.php#L65)

Jeżeli zostaną współdzielone writery: #59 rejestruje registry jako singleton,
#62 ma scoped writer ze stanem budżetu I/O. Nie przenosić budżetu, checkpointu
ani tożsamości między kolejnymi jobami długowiecznego workera. Wspólny planner
może być bezstanowy; stan wykonania musi należeć do jednego runu.

### Zaktualizowany podział pracy i testy

1. Wspólny mały fundament: role Source/ExportTarget, zakresy uprawnień zależne
   od operacji, guard mutacji i kontrakt zmian banku. Bez jednoczesnego uruchamiania
   obu implementacji eksportu.
2. #59 pozostaje osobnym source sync, dostosowanym do tych granic.
3. #61 stanowi trwały fundament eksportu; #62 zostaje zależnym rozszerzeniem
   linked z wcześniej wskazanymi poprawkami wspólnymi. Nadal jeden schemat
   export_operations, historia celu i dispatcher eksportu.
4. Złożyć i zweryfikować komplet na aktualnym main przed akceptacją merge.
   Współdzielenie planera/transportu można zrobić osobnym krokiem po testach.

Do poprzedniej macierzy dodać:

- sync targetu przez HTTP, bezpośrednią akcję, scheduler i już zakolejkowany job: odmowa przed zapisem;
- konto Spotify ze starymi scopes: private export nadal działa, nowa funkcja sync żąda potrzebnej zgody;
- usunięcie pozycji podczas export review z auto-sync włączonym i wyłączonym;
- confirm podczas pull/push oraz sync przed i po zamrożeniu manifestu eksportu;
- trzy typy operacji konkurujące o ostatnie miejsce w globalnym admission i retry po zmianie dnia;
- unlink/wyłączenie sync między mutacjami, bez dalszych zapisów;
- utrzymanie YouTube równocześnie chroni źródła pod sync i cele eksportu;
- placeholdery i duplikaty zachowane w banku, właściwa projekcja zapisów każdego workflow;
- dwa kolejne joby w jednym workerze nie dzielą stanu I/O;
- pełne testy SQLite/PostgreSQL i kontrole migracji na zestawie wszystkich trzech PR-ów.

### Aktualizacja weryfikacji

Ponownie wykonano porównania Git i odczytano kod oraz CI. Nie uruchamiano nowego
połączonego zestawu testów ani realnych API; nie powstała implementacja integracji.

- #59 `dbf48d2`: application, source-security i postgres-smoke PASS w [CI](https://github.com/Polkiujhy/music-map/actions/runs/34889304555). Podczas analizy powstał `61de763` z samymi zmianami dokumentacji; jego nowe CI miało application PASS, pozostałe kontrole trwały/oczekiwały przy ostatnim odczycie.
- #61 `6765519`: wszystkie cztery kontrole PASS w [CI](https://github.com/Polkiujhy/music-map/actions/runs/34889309953).
- #62 `f40b1c5`: application/source-security PASS, postgres-smoke nadal FAIL: **26 błędów, 593 PASS**, obrazy pominięte w [CI](https://github.com/Polkiujhy/music-map/actions/runs/34889135338). Obecna liczba zastępuje wcześniejsze 27 dla starszej rewizji. Log nadal pokazuje nadmiarowy rekord canary; StartExportOperationTest nie zmienił się od lokalnego odtworzenia.

Najważniejsza zmiana rekomendacji po dodaniu #59: wspólne granice i testy trzech
funkcji, ale dwie celowo odrębne polityki wykonania — source sync oraz eksport.
