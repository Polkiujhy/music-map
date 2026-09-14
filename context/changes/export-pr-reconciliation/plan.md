# Plan: integracja PR 59, 61 i 62

## Przegląd

`export-pr-reconciliation` (S-11) zastępuje, scala i prowadzi do wspólnego
domknięcia S-06 `managed-account-export`, S-07 `linked-account-export` oraz
S-08 `source-playlist-sync`. Użytkownik przenosi playlistę na konto linked lub
managed przy działającym sync źródła, bez przejęcia niewłaściwego celu,
utraty banku ani niejednoznacznego wyniku.

To korekta istniejącego planu, nie nowy start implementacji. Fazy 1–4 zachowują
zakończone kroki lokalnego scalenia. Faza 5 przejmuje wspólną akceptację ręczną;
dopiero jej zaliczenie pozwala domknąć trzy wyniki. Jedyną bieżącą checklistą
wykonania jest końcowe `## Progress` tego planu.

## Analiza bieżącego stanu

Kod połączono na `integrate/pr-59-61-62`: baza `38d6db8`, integracja
`97e1086`, epilog dowodów `e05bb5b`. Wszystkie trzy historie są przodkami gałęzi.
Trwały lifecycle #61 obsługuje linked z #62; sync #59 zachował odrębne runy.
Rozstrzygnięcia i wyniki: [implementation.md](implementation.md).

SQLite: 743 passed, 21 PostgreSQL-only skipped; PostgreSQL 15: 764/764 passed.
Pint, build, source contract i walidacja Composer przeszły na lokalnym kandydacie.
To nie jest dowód CI PostgreSQL 18, publikacji schematu ani rzeczywistych zapisów.
Nie wykonano push, zdalnego merge, deploy ani live smoke.

Przejmujemy 36 punktów ręcznych wskazanych przez użytkownika oraz dodatkową
ręczną bramkę PostgreSQL L:5.1. W sync historycznie zaliczono S:1.5 i S:2.6;
ich historia pozostaje nienaruszona, ale kontrola scalonego kandydata jest
ponownie otwarta. Sam merge nie zalicza żadnego live checku.

## Pożądany stan końcowy

- Jeden eksport linked/managed zachowuje frozen input, właściciela, zapisany
  cel, historię prób i retry; bank, review oraz mail są zgodne.
- Sync stosuje source-wins, nie dotyka celów eksportu i współdzieli F-02
  z eksportami bez naliczania retry jako nowej operacji.
- Kontrole ręczne mają dowód dla dokładnego kandydata i każdego wariantu
  provider × ownership, a także public/private Spotify source sync.
- S-11 domyka S-06/S-07/S-08 po akceptacji i późniejszej archiwizacji; do tego
  czasu pozostaje `in-progress`, a zmiana `implementing`. S-09 pozostaje poza MVP.

### Kluczowe odkrycia

- `app/Models/PlaylistExport.php` i `app/Models/PlaylistExportTargetAttempt.php`
  zachowują relację i historię locatorów; nie wraca `PlaylistExportLink` ani
  druga tabela operacji z #62.
- `app/Actions/ManagedAccountExport/StartConfirmedManagedExport.php` obsługuje
  oba konta. Powtórny confirm odzyskuje operację; historyczny confirmed bez
  operacji wymaga świeżego review, nie może uruchomić zapisu przez replay.
- `app/Actions/ManagedAccountExport/RequestManagedTargetRecreation.php` zachowuje
  logiczną operację i po jawnym potwierdzeniu dodaje generację celu. To zastępuje
  sprzeczną instrukcję #62 dotyczącą odtworzenia przez drugi runtime/nowy review.
- `tests/Feature/ManagedAccountExport/DurableExportTargetImportTest.php`
  obejmuje wyścig importu z locator checkpoint, także przed materializacją celu.
- `app/Integrations/ManagedAccountExport/WithLinkedAccountExportAccess.php`
  oddziela scopes prywatnego eksportu od public/private source sync; reconnect
  może wymienić lokalny rekord konta, ale nie providerową tożsamość operacji.

## Czego NIE robimy

- Korekta dokumentacji nie zleca push, zdalnego merge, deploy, rotacji ani realnych
  mutacji providerów. Wykonanie tych działań wymaga osobnego upoważnienia i kont
  testowych; nie uruchamiamy automatycznie fazy 5.
- Nie odtwarzamy runtime #62 ani nie implementujemy ponownie #59/#61.
- Nie dodajemy S-09 drift recovery, S-10 usuwania konta ani obsługi ponad 20 pozycji.
  Gotowość relacji dla S-09 nie oznacza wykonania FR-014.
- Nie przenosimy wnętrza s-manager do aplikacji ani nie rozszerzamy PaaS.
  Nie używamy probe jako fallbacku managed i nie utrwalamy technicznych tokenów.
- Nie zaliczamy smoke przez fake HTTP, nie wykonujemy fault injection na danych
  użytkowników i nie obiecujemy exactly-once maila.

## Podejście do implementacji

Review zamraża decyzję, wspólny trwały eksport wykonuje ją dla linked/managed,
a odrębny source sync uzgadnia bank z oryginałem. Wspólne granice chronią
własność, tożsamość celu i admission, nie łączą maszyn stanów obu przepływów.
Fazy 1–4 opisują wykonaną integrację; błąd znaleziony w akceptacji wymaga poprawki
i regresji kontraktu, nie kasowania historycznie zakończonych kroków.

Faza 5 scala powtarzalne kontrole w 18 bramek. Macierz pochodzenia zachowuje
każdy stary numer; procedury i raport dowodów nie tworzą drugiej checklisty.

## Krytyczne szczegóły implementacji

### Sekwencjonowanie stanu

Operation ID i frozen input przetrwają commit przed dispatch/admission.
Nieznany wynik create wymaga recovery, nigdy automatycznego drugiego create;
jawne recreate zachowuje historię, zwykłe retry także bieżący marker i cel.
Nie upraszczać tego do semantyki usuniętej implementacji #62.

### Czas i cykl życia

Import i checkpoint locatora serializują krótką zmianę w kolejności user-first,
bez HTTP pod blokadą. Worker rewaliduje identity, credential i generację przed
mutacją; gdy import wcześniej utworzył niezależne źródło, eksport zatrzymuje
zapis zamiast przejmować źródło.

### Debugowanie i obserwowalność

Sekrety nie trafiają do operacji, checkpointów, kolejki, logów ani artefaktów.
Konieczne locatory/identity pozostają w domenowym persistence, nie w raportach
smoke. Kanoniczny link w aplikacji/mailu jest wynikiem produktu, nie materiałem
do kopiowania do raportu.

## Phase 1: Połączenie historii i schematu

### Przegląd

Zakończona integracja #61 → #59 → #62 na jednej gałęzi i jednym schemacie.

### Wymagane zmiany

**Pliki**: `app/Models/ExportOperation.php`, `app/Models/PlaylistExport.php`,
`app/Models/PlaylistExportTargetAttempt.php`, `app/Models/Playlist.php`,
`database/migrations/2026_09_14_020000_freeze_export_operation_metadata.php`,
`bootstrap/providers.php`, `scripts/verify-source-contract`.

**Cel**: Zachować trwałość operacji i źródeł bez kolidujących tabel, tras i modeli.

**Kontrakt**: Jedna tabela `export_operations`, relacja `playlist_exports`,
historia `playlist_export_target_attempts`; `origin=managed_target` chroni oba
rodzaje celu przez neutralne `isExportTarget`, `assertSource`, `scopeSourceOnly`.
Metadane zamraża addytywna migracja. Nie adoptujemy importu jako celu eksportu.

### Kryteria sukcesu

- Automatyczne: trzy PR-y są przodkami integracji; świeże migracje i backfill
  przechodzą bez drugiego schematu eksportu oraz utraty relacji.
- Ręczne: własność, usuwanie, persistence i publikacja mają wspólny odbiór
  w 5.1, 5.2 i 5.5; merge nie stanowi ich zaliczenia.

## Phase 2: Wspólny eksport

### Przegląd

Zakończone przeniesienie linked na trwały lifecycle #61.

### Wymagane zmiany

**Pliki**: `app/Actions/ManagedAccountExport/StartConfirmedManagedExport.php`,
`app/Actions/ManagedAccountExport/RunManagedExport.php`,
`app/Actions/ManagedAccountExport/ResolveManagedExportTarget.php`,
`app/Integrations/ManagedAccountExport/WithLinkedAccountExportAccess.php`,
`app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php`,
`app/Integrations/ManagedAccountExport/Providers/SpotifyManagedPlaylistGateway.php`,
`app/Integrations/ManagedAccountExport/Providers/YouTubeManagedPlaylistGateway.php`,
`resources/views/livewire/export-review-panel.blade.php`,
`resources/views/bank/index.blade.php`.

**Cel**: Oba modele własności mają ten sam trwały wynik, retry i czytelny UI.

**Kontrakt**: Tracks i metadata są zamrożone; konto operacji nie zmienia się przy
reconnect. Token przy starcie dostępu ma co najmniej 390 s ważności. Linked używa
OAuth użytkownika, managed publicznego `music-map.managed-export.v1`. Retry
zachowuje operation, target i admission; jawne recreate dodaje generację celu,
nie usuwa wcześniejszego locatora/markera. Exact wynik zachowuje kolejność oraz
duplikaty. Znany linked target może odzyskać metadane po ID, unknown create
wymaga markera. UI/mail odróżniają konto użytkownika i konto zarządzane.

### Kryteria sukcesu

- Automatyczne: workflow, recovery, identity, unlink, frozen metadata, UI
  i notification przechodzą na jednym modelu; mapowanie zastąpionych testów
  znajduje się w `implementation.md`, nie istnieje drugi silnik.
- Ręczne: PaaS, gatewaye, awarie, UI i oba smoke mają wspólny odbiór w fazie 5.

## Phase 3: Granice synchronizacji

### Przegląd

Zakończone zabezpieczenie eksportu, importu, sync i maintenance.

### Wymagane zmiany

**Pliki**: `app/Actions/PlaylistSync/RunPlaylistSynchronization.php`,
`app/Actions/PlaylistSync/PreparePlaylistSynchronization.php`,
`app/Actions/PlaylistSync/ConfirmPlaylistSynchronization.php`,
`app/Actions/Playlists/ImportPlaylist.php`,
`app/Actions/Playlists/ReplaceImportedPlaylist.php`,
`app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php`,
`app/Enums/StreamingProvider.php`, `routes/console.php`,
`tests/Feature/ManagedAccountExport/ExportTargetBoundaryTest.php`,
`tests/Feature/ManagedAccountExport/DurableExportTargetImportTest.php`.

**Cel**: Sync zmienia tylko własne źródło, eksport tylko swój cel; odmowa
dostępu lub admission zachowuje bank.

**Kontrakt**: Source-only obowiązuje na HTTP, akcjach, jobach, login/scheduler
i maintenance, także dla znanych historycznych target attempts. Maintenance
pomija cele i sync pending/enabled/attention. Source-wins rozstrzyga konflikt
bez push; unlink zatrzymuje kolejne mutacje. Public-modify Spotify dotyczy
sync, nie prywatnego eksportu. F-02 rozróżnia `SourceSync`, `LinkedExport`,
`ManagedExport`; retry używa niezmiennego ID swojej logicznej operacji.

### Kryteria sukcesu

- Automatyczne: granice źródło/cel, scopes, maintenance, admission i wyścig
  import/checkpoint przechodzą również na PostgreSQL.
- Ręczne: preview, public/private qualification, source-wins, over-limit
  i reconnect są odbierane w 5.9, 5.12–5.15 razem z eksportem.

## Phase 4: Weryfikacja

### Przegląd

Zakończona lokalna regresja; nie zastępuje wyniku CI ani live smoke.

### Wymagane zmiany

**Pliki**: `tests/Feature/ManagedAccountExport/`, `tests/Feature/PlaylistSync/`,
`tests/Unit/Integrations/ManagedAccountExport/`, `scripts/verify-source-contract`,
`.github/workflows/ci.yml`, `context/changes/export-pr-reconciliation/implementation.md`.

**Cel**: Udowodnić lokalną zgodność scalonych kontraktów i zapisać ograniczenia dowodu.

**Kontrakt**: Pełne suite SQLite i disposable PostgreSQL, Pint, produkcyjny build
oraz source contract przechodzą na tym samym kodzie. Testy współbieżności nie
używają produkcji. Ledger wskazuje istniejące commity, nie hipotetyczny release.

### Kryteria sukcesu

- Automatyczne: wyniki z `implementation.md` i commity odpowiadają zachowanym
  krokom 4.1–4.3. Korekta dokumentacji przechodzi kontrolę Progress, ścieżek,
  pełnego pochodzenia manual checków i spójności roadmapy.
- Ręczne: dokładny przyszły release, opublikowany schemat i dowód PostgreSQL/CI
  pozostają w 5.18; lokalny PG15 nie zamyka tej bramki.

## Phase 5: Wspólna akceptacja S-06, S-07 i S-08

### Przegląd

18 bramek przejmuje 36 wskazanych kontroli oraz L:5.1. Wszystkie są otwarte.
Brak kont, zgody, zgodnego PaaS lub bezpiecznej metody przerwania pozostawia
punkt otwarty; mock ani „nie dotyczy” nie zastępuje wymaganego live smoke.

### Wymagane zmiany

**Pliki**: ten `plan.md` (tylko `Progress` przechowuje stan), `plan-brief.md`,
`change.md`, `context/foundation/roadmap.md` oraz przyszły
`context/changes/export-pr-reconciliation/reviews/manual-verification.md`.

**Cel**: Jeden kompletny odbiór bez dublowania checków i gubienia wariantów.

**Kontrakt**: Przyszły raport jest dowodem, nie drugą checklistą. Dla numeru 5.x
zapisuje commit/release, UTC, rolę weryfikatora, wariant, PASS/FAIL/BLOCKED,
bezpieczne obserwacje i referencję dowodu bez identyfikatorów. Nie tworzymy dziś
raportu z fikcyjnymi wynikami. Checkbox wymaga akceptacji wszystkich
podprzypadków. Poprawka po FAIL wymaga regresji i nowej tożsamości kandydata.

### Kryteria sukcesu i procedury ręczne

1. **5.1 — Schemat i własność.** Sprawdzić jeden aktywny cel na źródło/provider/
   konto, osobny snapshot, restrictive FK usuwania i historię prób. Znany lub
   historyczny locator nie może zostać przejęty przez import/edit/review/sync;
   niezależny import nie może zostać adoptowany przez eksport. Relacje zachowują
   dane potrzebne przyszłym S-09/S-10 bez implementowania tych funkcji.
2. **5.2 — Persistence i poufność.** Obejrzeć schemat, joby, checkpointy, failed
   jobs, sanityzowane logi i granice writerów używających zapisanych ID. Brak
   tokenów, zbędnych danych osobowych, surowych payloadów i szczegółów s-manager.
   Konieczne domenowe locatory/identity nie oznaczają zgody na ich logowanie.
3. **5.3 — Produkcyjny PaaS.** Operator potwierdza opublikowany kontrakt
   `music-map.managed-export.v1`: managed write zwykłego workera, identity/scopes,
   trwała i bezpieczna przy konkurencji rotacja replacement refresh tokenu
   przed udostępnieniem access tokenu, zamknięte błędy i zero mutacji przy odmowie.
   Aplikacja używa `MUSIC_MAP_MANAGED_EXPORT_SOCKET` tylko w roli queue; brak
   fallbacku do probe/tester/user credentials. Statyczna zgodność nie wystarcza:
   działanie na kandydacie musi potwierdzić późniejszy smoke i 5.18. Bez kontraktu
   nie publikować wspólnej orkiestracji ani linked-only.
4. **5.4 — Role runtime.** Zwykłe queue i scheduler obsługują sync, due/login,
   retry i recovery bez rozszerzenia PaaS. OAuth sync pozostaje aplikacyjny;
   scheduler nie otrzymuje technicznego tokenu.
5. **5.5 — Publikacja addytywna.** Po upoważnieniu i przeglądzie 5.1–5.4
   opublikować zgodny schemat przed uruchomieniem zapisów. Sprawdzić migracje/
   backfill na izolowanej bazie zgodnej ze schematem wydania, zachowanie źródeł,
   brak replay historycznych confirmed i kod rozpoznający cele. Zapisać
   bezpieczny dowód kandydata i możliwość zatrzymania zapisów.
6. **5.6 — Markery i paginacja.** Na realnych kontach obu providerów/obu trybów
   sprawdzić owner, visibility, round-trip markera po create i metadata update
   oraz recovery po kontrolowanej utracie odpowiedzi. Pełny scan obu kont managed
   potwierdza najwyżej 1000 playlist (20 stron po maksymalnie 50). Niepełny scan/
   limit daje wynik nierozstrzygnięty, nie zgodę na create. Nie tworzyć tysiąca
   zasobów do testu. Przekroczenie lub brak pełnego dowodu blokuje dalszy smoke
   i wydanie do decyzji o jawnej strategii recovery.
7. **5.7 — Duplikaty YouTube.** Zweryfikować kolejność i osobne occurrences
   na realnym koncie w obu eksportach i sync. Brak wsparcia/niepewność nie może
   dać cichej deduplikacji ani sukcesu. Brak odmowy przed mutacją dla znanego
   ograniczenia jest FAIL wymagającym poprawki, nie akceptowalnym obejściem.
8. **5.8 — Przerwania i retry.** Przejść commit→dispatch, admission→write,
   create→locator, replacement i remote success→local commit. Sprawdzić brak
   HTTP pod transakcją, checkpoint przed kolejną mutacją, stale-worker guard
   oraz ten sam operation/marker/target/admission przy zwykłym retry. Błąd przed
   mutacją odróżnia się od częściowego/nieznanego skutku po niej. Stany domenowe
   `queued`, `processing`, `succeeded`, `failed`, `partial_failed` i dodatkowe
   recovery/recreate mapują się na pięć komunikatów FR-011 i właściwą akcję,
   nie na enumy usuniętego runtime #62.
9. **5.9 — Pierwszy sync i kwalifikacja.** Podgląd pokazuje kierunek, liczność
   i skutek; stale preview wymaga odświeżenia. Własne publiczne i prywatne
   Spotify kwalifikują się po owner check i właściwym reconnect; collaborator/
   obce konto nie. Brak public-modify nie blokuje prywatnego eksportu.
10. **5.10 — UI i copy.** Przejść import→review/sync→confirm→status→retry/recreate
    i bank na desktopie/mobile, klawiaturą, czytnikiem, z 200% zoom i w dwóch
    kartach. Sprawdzić fokus, aria-live bez powtórzeń, zakończenie pollingu,
    właściciela, miejsce edycji, stały link, skutki recreate i ostrzeżenie,
    że Remove z banku przy auto-sync może zmienić oryginał.
11. **5.11 — Długa operacja.** Dla linked i managed opuścić ekran operacji
    trwającej co najmniej 60 s; mail prowadzi do właściwego owner-scoped wyniku.
    Błąd dostawy jest ponawiany, zwykłe retry po checkpointcie nie dubluje maila.
    Udokumentować at-least-once i możliwy duplikat send-before-checkpoint,
    zamiast wymagać exactly-once.
12. **5.12 — Source-wins i degradacja.** Oba providery: over-limit (>20),
    utrata dostępu i reconnect zachowują bank/identity i wskazują działanie.
    Konflikt aktywnego sync cicho odtwarza źródło, bez ekranu rozstrzygania
    i bez push. Unlink między mutacjami zatrzymuje dalsze zapisy; eksport
    zachowuje frozen manifest niezależnie od sync.
13. **5.13 — Spotify smoke.** Osobne linked i managed: create, nowe potwierdzenie
    →update tego samego ID, exact order/duplikaty, marker recovery, brak drugiego
    create dla zero/wielu wyników, `public=false`, stały link i brak publikacji
    na profilu (bez obietnicy kontroli dostępu przez sam link). Osobno własne
    publiczne i prywatne źródło: aktywacja, pull, push, source-wins i reconnect
    przy działającym eksporcie.
14. **5.14 — YouTube smoke i F-02.** Osobne linked i managed create/update tego
    samego ID, `unlisted`, link, marker recovery, stable exact order, duplikaty/
    fail-closed; osobno source pull, push i retry tego samego operation ID po
    kontrolowanym przerwaniu. Wspólne F-02 dla trzech typów, jedno admission
    przy retry, brak admission dla pull/no-op i zero write przy odmowie/awarii.
    Jeśli live retry nie można wykonać bezpiecznie, punkt zostaje otwarty;
    opcjonalny fallback ze starego smoke-test.md nie domyka tej akceptacji.
15. **5.15 — Awarie integracji.** Oba providery/tryby: partial retry tego samego
    celu, unlink/relink tej samej identity, odmowa innej identity, target deletion
    i jawne recreate z historią, quota/rate-limit i notification. Bez drugiej
    automatycznej kopii i przejęcia źródła przez retry/recreate/import.
    Fault injection kontrolowane; nie wyczerpywać produkcyjnej kwoty ani F-02.
16. **5.16 — Cleanup.** Po każdym smoke, także FAIL, zatrzymać jego auto-sync
    i retry; przywrócić stan albo usunąć tylko zatwierdzone testowe zasoby obu
    providerów i banku. Potwierdzić brak pozostałych operacji, usunąć prywatne
    scratch records. Uwzględnić restrictive FK managed: nie obchodzić ich surowym
    cascade/delete ani nie usuwać locatora przed cleanup zewnętrznego zasobu.
17. **5.17 — Artefakty.** Po cleanup skontrolować logi, failed jobs, wiadomości,
    zrzuty i raport: bez sekretów, surowych odpowiedzi, account IDs, playlist/
    item IDs i provider-controlled error text. Raport zapisuje wynik sprawdzenia
    linku/identity, nie URL/ID. Dozwolony link produktu w mailu nie jest kopiowany
    do dowodu. Wyciek oznacza FAIL, zabezpieczenie materiału i autoryzowaną
    revokację/rotację, nie samo zamazanie.
18. **5.18 — Odbiór kandydata.** Komplet 5.1–5.17, dokładny opublikowany kontrakt
    rotacji działający w smoke, zgodność schematu wydania i pełny PostgreSQL
    na izolowanej bazie zgodnej z kandydatem/CI, bez skipów współbieżności.
    Sprawdzić świeże quality gates i audyty zależności. Dowody pojedynczego PR
    nie zastępują scalonego release. Dopiero zatwierdzony komplet pozwala
    przejść do archiwizacji S-11 i trzech historycznych wyników.

### Pochodzenie scalonych kontroli

L = [linked-account-export](../linked-account-export/plan.md),
M = [managed-account-export](../managed-account-export/plan.md),
S = [source-playlist-sync](../source-playlist-sync/plan.md).
Numery są historyczne. Powtórne wskazanie źródła rozdziela jego złożone kryterium,
nie wymaga ponownego wykonania identycznego testu.

| Wspólna bramka | Historyczne punkty przejęte do akceptacji |
| --- | --- |
| 5.1 | L:1.4; M:1.5 |
| 5.2 | M:1.6; L:3.6; S:1.5; S:3.7 |
| 5.3 | L:2.10; M:2.6; M:5.11 |
| 5.4 | S:4.7 |
| 5.5 | L:5.8 |
| 5.6 | L:2.5; L:2.8; M:2.7 |
| 5.7 | L:2.6 |
| 5.8 | L:3.5; M:3.6; M:3.7 |
| 5.9 | S:2.6; S:2.7 |
| 5.10 | L:4.5; M:4.6; M:4.7; S:5.5 |
| 5.11 | L:4.6 |
| 5.12 | S:5.6; S:5.7 |
| 5.13 | L:5.5; M:5.8; S:6.6 |
| 5.14 | L:5.6; M:5.9; S:6.7 |
| 5.15 | L:5.7 |
| 5.16 | M:5.8; M:5.9; S:6.8 |
| 5.17 | L:5.8; M:5.10; S:6.8 |
| 5.18 | L:5.1; M:5.11 |

## Strategia testowania

### Testy jednostkowe i integracyjne

Zachować gateway/access, exact order/duplikaty, frozen input, ownership,
source-wins, admission i recovery. Pełna regresja obejmuje SQLite i prawdziwe
PostgreSQL locks/races na jednorazowej bazie; nigdy `migrate:fresh` na produkcji.
Po błędzie smoke: test regresji, poprawka, ponowienie dotkniętej macierzy.
Świeże gates przyszłego release: `composer test`, `vendor/bin/pint --test`,
`npm run build`, `composer validate --strict --no-interaction`,
`composer audit --locked --no-interaction`, `npm audit --audit-level=high`,
`sh scripts/verify-source-contract --worktree` i `--tracked` dopiero po
przygotowaniu kandydata w indeksie w ramach odrębnie zleconego wydania.

### Kolejność ręczna i warunki stopu

Najpierw 5.1–5.4, następnie nadzorowana 5.5, potem 5.6–5.15.
Cleanup 5.16 wykonuje się również po awarii, nie dopiero po sukcesie macierzy.
Kończą 5.17–5.18. Brak bezpiecznej metody, dostępu lub upoważnienia blokuje
scenariusz; nie upoważnia do zmiany hosta/PaaS ani zapisu na obcych playlistach.
Smoke używa dedykowanych kont i playlist do 20 pozycji, z wyjątkiem jawnego
scenariusza odmowy over-limit; bez masowego tworzenia zasobów.

## Zagadnienia wydajnościowe

Bank ładuje bounded status, nie całą historię ani N+1. YouTube stosuje minimalne
mutacje i stabilne odczyty; limit 20 stron recovery nie pozwala na niepełny scan.
Obowiązują limity scalonego runtime, nie timeout/lease usuniętej implementacji
#62. Regresja sprawdza zgodność joba, overlap i czasu ponownej dostawy kolejki.

## Uwagi dotyczące migracji i wycofania

Migracje addytywne poprzedzają realne zapisy; backfill nie uruchamia historycznych
review. Przed publikacją ustalić, czy środowisko ma bazowy schemat, czy któryś PR
wdrożono osobno. Drugi przypadek wymaga osobnego przeglądu/mapowania danych;
plan nie upoważnia do usuwania tabel lub historii dla dopasowania do scalenia.

Po zapisach rollback kodu może wrócić tylko do wersji rozumiejącej target
boundaries i locatory. Najpierw zatrzymać nowe dispatch/mutacje przez publiczny
runtime, zachować operacje/history, potem zgodny kandydat lub poprawka forward.
Nie wykonywać destrukcyjnego `down()` ani automatycznej kompensacji zewnętrznych
playlist. Cleanup dotyczy tylko zatwierdzonych danych testowych, nie S-10.

## Commit ledger i stan dokumentacji

- Phase 1: #61 `c668166`, #59 `9cd6d7e`, #62 i integracja `97e1086`.
- Phase 2–3: `97e1086` — wspólny eksport, source guards i import/checkpoint.
- Phase 4: kod/testy `97e1086`, epilog dowodów `e05bb5b`.
- Użytkownik następnie autoryzował commit korekty, push gałęzi, nowy PR integracyjny
  i zamknięcie historycznych PR-ów jako zastąpionych. Żaden z powyższych SHA
  nie dowodzi nowej fazy 5; publikacja schematu i live smoke pozostają otwarte.
  Zgoda na PR nie jest zgodą na merge ani wdrożenie.

## References

- [Brief](plan-brief.md), [badania](research.md), [wynik integracji](implementation.md).
- [PRD](../../foundation/prd.md): US-01, US-02, FR-005, FR-009–FR-011,
  NFR-001–NFR-004 i NFR-006; FR-014/S-09 poza bieżącym MVP.
- [Roadmapa](../../foundation/roadmap.md): S-11 zastępujące S-06/S-07/S-08.
- Historyczne plany L/M/S wskazane w macierzy; sprzeczne instrukcje runtime
  zastępują ten plan i `implementation.md`.
- [Protokół managed](../managed-account-export/reviews/manual-verification.md)
  i [smoke sync](../source-playlist-sync/smoke-test.md): historyczne materiały,
  nie niezależne checklisty ani dowody scalonego kandydata.
- `AGENTS.md`, `README.md`, skill `s-manager-use` i jego
  `references/music-map-managed-export.md`: publiczny kontrakt konsumenta.
- Użytkownik: „wykonaj scalenie”; następnie korekta zgodna z `$10x-plan`,
  scalenie manual checków i wpis zastępujący/domykający zgodny z `$10x-roadmap`.

## Progress

### Phase 1: Połączenie historii i schematu

#### Automated

- [x] 1.1 Trzy historie połączone bez konfliktów i podwójnych schematów — 97e1086

### Phase 2: Wspólny eksport

#### Automated

- [x] 2.1 Linked i managed używają trwałego lifecycle z testami odzyskiwania — 97e1086

### Phase 3: Granice synchronizacji

#### Automated

- [x] 3.1 Source sync i eksport respektują wspólne granice oraz zakresy dostępu — 97e1086

### Phase 4: Weryfikacja

#### Automated

- [x] 4.1 SQLite i PostgreSQL przechodzą na scalonym kodzie — 97e1086
- [x] 4.2 Pint, build i source contract przechodzą — 97e1086
- [x] 4.3 Wynik scalenia zapisany w lokalnych commitach i dokumentacji — e05bb5b

### Phase 5: Wspólna akceptacja S-06, S-07 i S-08

#### Manual

- [x] 5.1 Schemat zachowuje własność, restrykcyjne usuwanie i przyszłe relacje S-09
- [ ] 5.2 Persistence i writerzy używają właściwych ID bez sekretów i szczegółów PaaS
- [ ] 5.3 Produkcyjny kontrakt managed write i rotacji jest potwierdzony dla zwykłego workera
- [ ] 5.4 Standardowe role queue i scheduler obsługują sync bez rozszerzenia PaaS
- [ ] 5.5 Addytywna publikacja schematu zachowuje źródła, historię i bezpieczny cutover
- [ ] 5.6 Markery, owner, visibility i pełny scan obu providerów respektują limit 1000 playlist managed
- [ ] 5.7 Duplikaty YouTube zachowują dokładny wynik albo bezpieczne fail-closed
- [ ] 5.8 Punkty przerwania, sekwencje mutacji i retry zachowują tożsamość oraz FR-011
- [ ] 5.9 Pierwszy podgląd i kwalifikacja publicznych oraz prywatnych Spotify są jednoznaczne
- [ ] 5.10 Panel, bank i sync przechodzą desktop, mobile, dostępność i przegląd komunikatów
- [ ] 5.11 Długa operacja powiadamia o właściwym wyniku z bezpiecznym retry dostawy
- [ ] 5.12 Over-limit, utrata dostępu, reconnect i konflikt source-wins zachowują bank
- [ ] 5.13 Spotify przechodzi pełny smoke linked, managed i własnych źródeł public/private
- [ ] 5.14 YouTube przechodzi pełny smoke linked, managed, source pull/push i retry F-02
- [ ] 5.15 Partial retry, relink, target deletion, quota i notification spełniają wspólny kontrakt
- [ ] 5.16 Dane obu providerów, banku i aktywne operacje testowe są bezpiecznie posprzątane
- [ ] 5.17 Artefakty smoke nie zawierają sekretów, surowych payloadów ani providerowych identyfikatorów
- [ ] 5.18 Dokładny kandydat ma pełny odbiór ręczny, dowód PostgreSQL i zgodnej rotacji PaaS
