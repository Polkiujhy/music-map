# Eksport na konto techniczne music-map — plan implementacji

## Przegląd

S-06 domyka pierwszy pionowy przepływ eksportu. Po świadomym potwierdzeniu
wyniku S-05 aplikacja ma atomowo utrwalić jedną logiczną operację, wykonać ją w
tle na technicznym koncie Spotify albo YouTube i zwrócić stały link, właściciela
oraz jednoznaczny status. Ponowienie po częściowym zapisie aktualizuje tę samą
playlistę po zapisanym ID i korzysta z tej samej rezerwacji F-02.

Zmiana obejmuje oba providery. Spotify tworzy playlistę z `public=false`, a
YouTube z `privacyStatus=unlisted`. Nazwa pochodzi z banku; opis jawnie wskazuje
zarządzanie przez music-map, miejsce edycji i zawiera niesekretny marker
odzyskiwania.

## Analiza stanu obecnego

S-05 kończy się zamrożonym `ConfirmedExportManifest`. Potwierdzenie blokuje
review i playlistę, ponownie sprawdza fingerprint i cel, utrwala decyzje oraz
zwraca dokładnie zatwierdzone docelowe ID w kolejności. Kontroler i Livewire
odrzucają jednak wynik i pokazują tylko informację o gotowym manifeście
(`app/Actions/ExportReviews/ConfirmExportReview.php:26`,
`app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:13`,
`app/Http/Controllers/PlaylistExportReviewController.php:73`,
`app/Livewire/ExportReviewPanel.php:123`).

`ExportReviewStatus` opisuje wyłącznie przygotowanie i potwierdzenie review.
Repozytorium nie ma operacji eksportowej, relacji źródło–zewnętrzna kopia,
providerowego ID celu, stałego linku ani statusów FR-011
(`app/Enums/ExportReviewStatus.php:5`,
`database/migrations/2026_09_14_000100_create_export_reviews_table.php:11`,
`app/Models/Playlist.php:13`).

F-02 dostarcza trwałe, atomowe dopuszczenie zapisów YouTube. Port wymaga
stabilnego ID konsumenta, odrzuca użycie w otwartej transakcji, zachowuje
rezerwację bezterminowo i rozróżnia nowe dopuszczenie, istniejącą rezerwację,
limit oraz awarię techniczną
(`app/Integrations/YouTubeWriteAdmission/Contracts/AdmitYouTubeWrite.php:9`,
`app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php:29`).

Konfiguracja technicznych kont istnieje, lecz `PlatformAccess` jest probe'em
akceptacyjnym, nie gatewayem produktu. Jego technical principal dowodzi
identity/read/refresh, a nie domenowego eksportu
(`app/Integrations/PlatformAccess/PlatformAccessProtocol.php:35`,
`context/archive/2026-09-12-platform-access-readiness/plan-brief.md`).

Kolejka bazodanowa ma lease 510 sekund, a obecny długi job stosuje timeout 450
sekund, ograniczone retry oraz `WithoutOverlapping`
(`config/queue.php:38`, `app/Jobs/PrepareExportReview.php:22`). Samo
`afterCommit()` nie atomizuje commitu z enqueue, dlatego trwała operacja musi
również pełnić rolę odzyskiwalnej intencji.

## Pożądany stan końcowy

Potwierdzenie zarządzanego review jednocześnie zamraża manifest i tworzy lub
odnajduje dokładnie jedną trwałą operację. Po commit job uzyskuje techniczny
dostęp, a dla YouTube najpierw commitowane dopuszczenie F-02. Następnie tworzy
lub odnajduje po markerze właściwą kopię, zapisuje providerowe ID przed zmianą
pozycji i doprowadza zdalną zawartość oraz metadane do dokładnego manifestu.

Użytkownik widzi w review i banku: `W trakcie przenoszenia`, `Przeniesiona —
zarządzana przez music-map`, `Nie przeniesiono` albo `Nie udało się dokończyć
przenoszenia`. Sukces pokazuje stały link, właściciela i instrukcję edycji.
Po stabilnym sukcesie docelowy snapshot staje się osobną playlistą bankową
powiązaną ze źródłem. Awaria częściowa oferuje retry tej samej operacji. Utrata
zapisanego celu albo niejednoznaczny wynik create wymaga jawnej zgody na
odtworzenie; żaden automat nie wykonuje drugiego create, dopóki pierwszy wynik
pozostaje nieznany.

### Kluczowe odkrycia

- Kanoniczna kopia musi być unikalna po źródle, providerze i rzeczywistym
  `target_account_id`; `destination_type` opisuje politykę, ale nie tożsamość
  fizycznego właściciela.
- Review pozostaje niezmiennym właścicielem manifestu. Operacja nie wykonuje
  ponownego matchingu ani nie kopiuje tokenów lub providerowych payloadów.
- Marker odzyskiwania nie jest dowodem autoryzacji. Kandydat musi dodatkowo mieć
  oczekiwanego właściciela i widoczność.
- Spotify nie gwarantuje, że opis zawsze wróci na liście playlist; po
  wyczerpaniu bezpiecznych odczytów potrzebny jest stan ręcznego odzyskania.
- Po niejednoznacznym create twardą ochroną przed duplikatem jest zakaz
  kolejnego create, nie sam marker.
- Zapisane providerowe ID jest write-once dla zwykłych retry. Jego zmiana
  wymaga jawnej akcji odtworzenia z nową generacją markera.

## Czego NIE robimy

- Nie eksportujemy na powiązane konto użytkownika; to zakres S-07.
- Nie implementujemy synchronizacji, wykrywania driftu ani usuwania konta z
  S-08–S-10.
- Nie wyszukujemy playlisty po tytule i nie wybieramy „najnowszego” kandydata.
- Nie wykonujemy ślepego appendu po częściowej awarii ani automatycznego create
  po 404, niepełnej paginacji lub nieznanym wyniku poprzedniego create.
- Nie rozszerzamy `ExportReviewStatus` o stany wykonania eksportu.
- Nie używamy probe'ów technical/tester jako gatewayów produktu.
- Nie utrwalamy access tokenów, refresh tokenów, surowych odpowiedzi providera
  ani tajnych wartości w bazie, job payloadach, logach i powiadomieniach.
- Nie kopiujemy mechaniki hosta, transportu sekretów, rotacji ani wdrożenia
  Managera; zapisujemy tylko wymagany publiczny kontrakt konsumenta.
- Nie gwarantujemy playlist większych niż 20 pozycji i nie uruchamiamy live API
  w zwykłym CI.

## Podejście do implementacji

Zmiana wprowadzi trzy oddzielne byty. `PlaylistExport` będzie kanoniczną relacją
źródło–zewnętrzna kopia, a `PlaylistExportTargetAttempt` zachowa każdą generację
markera i jej locator lub niejednoznaczny ślad. `ExportOperation` będzie jedną
logiczną próbą konkretnego potwierdzonego manifestu, trwałym ID dla F-02 oraz
źródłem statusu, retry i powiadomień. Niepełna kopia nie stanie się pełnoprawnym
`Playlist` w banku; dopiero stabilny sukces materializuje osobny docelowy
snapshot powiązany ze źródłem. Późniejsze wykrywanie driftu pozostaje w S-09.

Nowy moduł `ManagedAccountExport` dostarczy efemeryczny dostęp techniczny,
neutralny port playlisty, providerowe gatewaye, wersjonowane metadane i
zamknięte wyniki awarii. Warstwa aplikacyjna będzie właścicielem transakcji,
idempotencji i mapowania do FR-011; gatewaye nie będą znały UX.

Operacja jest durable outboxem. `afterCommit()` jest szybką ścieżką, natomiast
ograniczona komenda harmonogramu ponownie dispatchuje osierocone `queued` i
bezpiecznie stare `processing` dopiero po przekroczeniu pełnego lease.

Publiczny, wersjonowany kontrakt PaaS rotacji technical refresh tokenu dla
zwykłych workerów jest oczekującą zależnością zewnętrzną. Phase 2 może rozwijać
niezależne kontrakty domenowe i gatewaye z test doubles, ale jej integracja
produkcyjna jest zablokowana do publikacji tego kontraktu. Po publikacji, a
przed implementacją dostępu produkcyjnego, plan musi zostać uzupełniony o
dokładną przyjętą wersję oraz trwałe publiczne źródło kontraktu.
music-map zależy
wyłącznie od jego consumer-visible wersji, locatora/entrypointu, zamkniętego
schematu, potwierdzenia synchronicznego przejęcia i semantyki błędów; bez tej
gwarancji worker kończy fail-closed przed użyciem access tokenu.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Nie wolno trzymać transakcji ani blokady bazy podczas OAuth lub requestu
providera. Providerowe ID i URL trzeba zapisać w krótkiej transakcji natychmiast
po pewnym create, zanim rozpocznie się zapis pozycji. `attempt_generation` i CAS
chronią wynik przed spóźnionym workerem, a recovery nie redispatchuje aktywnego
`processing` przed upływem 510 sekund.

### Sekwencjonowanie stanu

Po niejednoznacznym create kolejne automatyczne próby wykonują wyłącznie
paginowany odczyt markera. `none` oznacza dopiero kompletny poprawny scan;
limit stron lub `next` poza limitem daje `inconclusive`. Jeden kandydat wymaga
zgodności ownera, markera i widoczności, wiele kandydatów zawsze zatrzymuje
automat. YouTube wymaga dwóch zgodnych obserwacji przed decyzją opartą na
pustym stanie lub prefiksie.

## Phase 1: Trwały kontrakt operacji i relacji

### Przegląd

Faza ustanawia przenośny schemat, zamknięte stany i relacje, które są źródłem
prawdy dla idempotencji, retry, UI i przyszłego usuwania zarządzanych kopii.

### Wymagane zmiany

#### 1. Kanoniczna relacja źródło–eksport

**Pliki**: `database/migrations/*_add_origin_to_playlists_table.php`,
`database/migrations/*_create_playlist_exports_table.php`,
`app/Enums/PlaylistOrigin.php`, `app/Models/PlaylistExport.php`,
`database/factories/PlaylistExportFactory.php`, `app/Models/Playlist.php`

**Cel**: Utrwalić jedną zewnętrzną kopię dla źródła, providera i technicznego
konta bez udawania kompletnego snapshotu bankowego podczas częściowego zapisu.

**Kontrakt**: Rekord należy do `source_playlist_id`, zapisuje provider,
`destination_type`, nullable `streaming_account_id`, niepusty snapshot
`target_account_id`, market, bieżącą generację celu oraz nullable, unikalny
`target_playlist_id`, ustawiany dopiero po stabilnym sukcesie. Unikalność
`source_playlist_id + target_provider + target_account_id` zapobiega drugiej
kopii po zmianie klasyfikacji celu. FK źródła, docelowego snapshotu i review są
restrykcyjne, a konto streamingowe może zostać wyzerowane bez utraty snapshotu
tożsamości. Para `id + target_provider + target_account_id` jest kluczem
kandydującym dla prób celu.

`Playlist` otrzymuje zamknięty discriminator `origin=imported|managed_target`;
istniejące rekordy oraz zwykły import mają `imported`. Obecny unikalny klucz
`user_id + source_provider + source_playlist_id` pozostaje tożsamością jednej
zewnętrznej playlisty w banku i nie jest rozszerzany o `origin`. Materializacja
może przejąć istniejący rekord o tym samym providerowym ID jako
`managed_target` wyłącznie po potwierdzeniu zgodnego providera oraz technicznego
ownera. Zarządzany target jest read-only w banku i nie uczestniczy w source
import/reimport, lokalnej edycji, przygotowaniu kolejnego eksportu ani w
automatycznym source refresh/purge.

#### 2. Historia prób utworzenia celu

**Pliki**: `database/migrations/*_create_playlist_export_target_attempts_table.php`,
`app/Models/PlaylistExportTargetAttempt.php`,
`database/factories/PlaylistExportTargetAttemptFactory.php`,
`app/Models/PlaylistExport.php`

**Cel**: Nie utracić jedynego markera możliwej osieroconej playlisty, gdy
użytkownik świadomie zezwoli na recreate po niejednoznacznym create.

**Kontrakt**: Każdy rekord należy do `playlist_export_id`, kopiuje provider i
`target_account_id` objęte złożonym FK do kanonicznej relacji, ma niezmienną i
unikalną w obrębie relacji generację oraz losowy marker, stan
`pending|unknown|resolved|abandoned`, znaczniki create i nullable provider ID
oraz canonical URL. `pending` oznacza brak rozpoczętego create, `unknown`
niejednoznaczny wynik mutacji, a `resolved` pewny locator. Znany provider ID jest
unikalny w obrębie providera i ownera. Zwykły retry zawsze używa tej samej
próby. Recreate atomowo oznacza bieżącą próbę jako `abandoned` i tworzy następną
generację; nigdy nie nadpisuje starego markera ani locatora. Wszystkie generacje
pozostają dostępne dla późniejszego recovery i cleanupu S-10.

#### 3. Trwała operacja eksportowa

**Pliki**: `database/migrations/*_create_export_operations_table.php`,
`app/Models/ExportOperation.php`, `database/factories/ExportOperationFactory.php`,
`app/Models/ExportReview.php`, `app/Models/User.php`

**Cel**: Zapisać dokładnie jedną logiczną operację na potwierdzone review i
wykorzystać ją jako trwałą intencję kolejki oraz stabilne ID F-02.

**Kontrakt**: `export_review_id` jest unikalnym restrykcyjnym FK,
`playlist_export_id` wskazuje kanoniczną kopię. Rekord zawiera UUID/ULID
mieszczący się w limicie F-02, status, bezpieczny failure code, attempt/retry
generation, trwały licznik automatycznych claimów w bieżącej retry generation,
heartbeat, dzierżawę publikacji joba, znaczniki pierwszej możliwej mutacji,
retry availability, manual recovery i powiadomienia. Nie przechowuje manifestu,
tokenów ani raw payloadów.

#### 4. Zamknięty język stanów

**Pliki**: `app/Enums/ExportOperationStatus.php`,
`app/Integrations/ManagedAccountExport/ManagedExportFailureCode.php`

**Cel**: Oddzielić cykl wykonania od cyklu review i uczynić retry policy
deterministyczną.

**Kontrakt**: Stany obejmują `queued`, `processing`, `succeeded`, `failed`,
`partial_failed`, `manual_recovery_required` i `recreate_required`. Pierwsze
dwa mapują się na stan w toku; sukces zależy od typu właściciela; `failed`
oznacza brak możliwej mutacji, a pozostałe terminalne stany zachowują locator
lub ślad niejednoznacznej mutacji. Kody awarii są zamknięte, bezpieczne dla
użytkownika i nie zawierają tekstu providera.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Migracje, rollback przed użyciem oraz testy constraintów przechodzą na
  SQLite: `php artisan test tests/Feature/ManagedAccountExport/ManagedExportMigrationTest.php`.
- Testy modeli i enumów dowodzą castów, relacji, write-once locatora, zachowania
  wszystkich generacji prób celu i braku pól sekretów:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportModelTest.php`.
- PostgreSQL potwierdza unikalność kanonicznej kopii oraz jednej operacji na
  review pod wyścigiem: dedykowane przypadki w
  `tests/Feature/ManagedAccountExport/ManagedExportPostgresTest.php`.
- Dotychczasowe kontrakty playlist, review i F-02 pozostają zielone:
  `php artisan test tests/Feature/ExportMatchReview tests/Feature/Integrations/YouTubeWriteAdmission`.

#### Weryfikacja ręczna

- Przegląd schematu potwierdza, że locator przetrwa retry i przyszłe usuwanie
  konta, a restrykcyjne FK nie pozwolą skasować go przed cleanupem providera.
- Przegląd danych potwierdza brak tokenów, raw response i danych osobowych w
  markerze oraz operacji.

**Uwaga implementacyjna**: Po zielonych testach zatrzymaj fazę do ręcznego
potwierdzenia modelu własności i semantyki usuwania.

---

## Phase 2: Dostęp techniczny i gatewaye providerów

### Przegląd

Faza tworzy osobny produktowy moduł dostępu i zapisu dla Spotify oraz YouTube,
bez rozszerzania probe'u F-01.

### Wymagane zmiany

#### 1. Efemeryczny dostęp do konta technicznego

**Pliki**: `app/Integrations/ManagedAccountExport/Contracts/WithManagedAccountAccess.php`,
`app/Integrations/ManagedAccountExport/Data/ManagedAccessContext.php`,
`app/Integrations/ManagedAccountExport/Data/ManagedAccessResult.php`,
`app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php`,
`config/services.php`, `.env.example`

**Cel**: Udostępnić access token wyłącznie wewnątrz synchronicznego callbacku po
sprawdzeniu konfiguracji, exact scope i tożsamości technicznego konta.

**Kontrakt**: Dostęp używa wyłącznie publicznych nazw runtime dla principal
technical, nie przyjmuje tester session ani `StreamingAccount` i nie ma
fallbacku. Replacement refresh token musi zostać synchronicznie przekazany
przez opublikowany, wersjonowany kontrakt rotacji dla zwykłego workera przed
użyciem access tokenu; implementacja zapisuje w referencjach dokładną przyjętą
wersję kontraktu. Brak kontraktu, jego niezgodność albo brak potwierdzenia
przejęcia daje `refresh-rotation-required` bez lokalnego zapisu sekretu. Testy
obejmują wyłącznie publiczne wejścia, wyjścia i gwarancje konsumenta, bez
mechaniki Managera.

#### 2. Neutralny kontrakt playlisty zarządzanej

**Pliki**: `app/Integrations/ManagedAccountExport/Contracts/ManagedPlaylistGateway.php`,
`app/Integrations/ManagedAccountExport/Data/*`,
`app/Integrations/ManagedAccountExport/ManagedProviderFailure.php`,
`app/Integrations/ManagedAccountExport/ManagedPlaylistGatewayRegistry.php`

**Cel**: Oddzielić create, read-only recovery, inspect i exact reconciliation,
aby locator mógł zostać zapisany przed zmianą pozycji.

**Kontrakt**: Port zwraca typowane `none|one|ambiguous|inconclusive` dla markera,
referencję z ID/URL/ownerem dla pewnego create, snapshot celu dla inspect oraz
wynik exact reconciliation. Registry wybiera provider po enum bez fallbacku.
Taksonomia rozróżnia config/auth/scope/account, rate/quota/transport,
nieprawidłową odpowiedź, missing target, owner/marker/visibility mismatch,
odrzucone metadata/item i niejednoznaczną mutację.

#### 3. Deterministyczne metadane i marker

**Pliki**: `app/Integrations/ManagedAccountExport/Data/ManagedPlaylistMetadata.php`,
`app/Integrations/ManagedAccountExport/ManagedPlaylistMetadataFactory.php`,
`app/Integrations/ManagedAccountExport/ManagedExportMarker.php`

**Cel**: Zachować rozpoznawalną nazwę bankową, jawną informację o zarządzaniu i
wersjonowany marker bez ryzyka jego obcięcia lub podszycia się przez opis.

**Kontrakt**: Marker jest losowym, 128-bitowym identyfikatorem ASCII bez user,
review i playlist ID. Renderer usuwa z wejścia zarezerwowany prefiks, rezerwuje
miejsce na suffix oraz umieszcza dokładnie jeden marker w ostatniej linii.
Providerowe limity są deklarowane i testowane w konkretnych gatewayach; suffix
i marker nigdy nie są obcinane.

#### 4. Spotify i YouTube gateway

**Pliki**: `app/Integrations/ManagedAccountExport/Providers/SpotifyManagedPlaylistGateway.php`,
`app/Integrations/ManagedAccountExport/Providers/YouTubeManagedPlaylistGateway.php`,
`app/Providers/ManagedAccountExportServiceProvider.php`, `bootstrap/providers.php`

**Cel**: Zrealizować providerowe create/recovery/inspect/reconcile przy
minimalnych requestach i pełnej walidacji celu.

**Kontrakt**: Spotify zawsze tworzy prywatny profilowo cel (`public=false`,
`collaborative=false`) i dla maksymalnie 20 URI wykonuje pełny replace oraz
bounded verify. YouTube tworzy `unlisted`, paginuje własne playlisty i pozycje,
przed decyzją opartą na pustym/prefiksowym stanie wymaga dwóch zgodnych
obserwacji, następnie usuwa lub dopisuje tak, aby końcowy porządek wraz z
duplikatami był dokładny. Każdy update YouTube ponownie wysyła pełne title,
description i visibility, aby nie usunąć markera.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy dostępu technicznego pokrywają exact config/scope/identity, rotację,
  brak fallbacku i brak wycieku sekretów:
  `php artisan test tests/Unit/Integrations/ManagedAccountExport/ManagedAccountAccessTest.php`.
- Testy markera i metadanych pokrywają Unicode, granice długości, reserved
  prefix oraz nieobcinany suffix:
  `php artisan test tests/Unit/Integrations/ManagedAccountExport/ManagedExportMarkerTest.php`.
- Fake HTTP Spotify dowodzi dokładnych payloadów, paginacji, widoczności,
  replace/verify i zamkniętego mapowania błędów:
  `php artisan test tests/Unit/Integrations/ManagedAccountExport/SpotifyManagedPlaylistGatewayTest.php`.
- Fake HTTP YouTube dowodzi kosztownego create dopiero po decyzji warstwy
  aplikacyjnej, stabilnych odczytów, kolejności, duplikatów, cleanup/rebuild i
  niejednoznacznych mutacji:
  `php artisan test tests/Unit/Integrations/ManagedAccountExport/YouTubeManagedPlaylistGatewayTest.php`.
- Regresje F-01 i Google login pozostają zielone:
  `php artisan test tests/Unit/Integrations/PlatformAccess tests/Feature/Console/ProbePlatformAccessTest.php tests/Feature/Auth/GoogleAuthenticationTest.php`.

#### Weryfikacja ręczna

- Przegląd publicznej granicy PaaS potwierdza wyłącznie konfigurację,
  entrypoint/locator i semantykę synchronicznej rotacji potrzebną konsumentowi,
  bez mechaniki Managera.
- Przegląd gatewayów potwierdza, że marker nigdy nie zastępuje owner, scope i
  visibility check oraz że niepełna paginacja nie prowadzi do create.

**Uwaga implementacyjna**: Produkcyjnej części dostępu technicznego nie wolno
rozpoczynać ani oznaczyć jako ukończonej przed publikacją kontraktu oraz
wpisaniem tutaj jego dokładnej wersji i trwałego publicznego źródła.
Po tej fazie zatrzymaj się do akceptacji kontraktu technicznego dostępu i
providerowych payloadów.

---

## Phase 3: Atomowy start i odporne wykonanie

### Przegląd

Faza łączy potwierdzenie z trwałą operacją i prowadzi ją przez admission,
create/recovery, checkpoint locatora oraz exact-state reconciliation.

### Wymagane zmiany

#### 1. Potwierdzenie rozpoczynające eksport

**Pliki**: `app/Actions/ExportReviews/ConfirmExportReview.php`,
`app/Actions/ManagedAccountExport/StartConfirmedManagedExport.php`,
`app/Http/Controllers/PlaylistExportReviewController.php`,
`app/Livewire/ExportReviewPanel.php`

**Cel**: Jednym świadomym potwierdzeniem atomowo zamrozić manifest i utworzyć
lub odnaleźć operację zarządzaną, bez luki pozwalającej na dwa logiczne starty.

**Kontrakt**: Wspólna transakcja zachowuje obecny lock order review→playlist→items,
blokuje cel z aktywną lub częściową operacją i idempotentnie zapisuje
`PlaylistExport` oraz `ExportOperation`. Double submit zwraca ten sam rekord.
Linked review nadal tylko potwierdza handoff dla S-07. Nowa operacja dispatchuje
się `afterCommit`; rollback nie pozostawia joba.

#### 2. Idempotentny worker i admission

**Pliki**: `app/Jobs/RunManagedExport.php`,
`app/Actions/ManagedAccountExport/RunManagedExport.php`

**Cel**: Wykonać jedną operację z trwałym claimem i nie dopuścić, aby retry lub
spóźniony worker nadpisał nowszy stan.

**Kontrakt**: Job ma timeout 450 sekund, lease/overlap 510 sekund, trzy próby i
backoff 60/300. Krótka transakcja claimuje `queued` lub bezpiecznie odzyskiwane
`processing`, zwiększa attempt generation i trwały licznik automatycznych
claimów w bieżącej retry generation, po czym wykonuje commit. Budżet wynosi
maksymalnie trzy automatyczne claimy na retry generation i nie resetuje się po
utworzeniu nowego joba przez recovery; po wyczerpaniu operacja przechodzi do
`partial_failed` albo `manual_recovery_required`. Dopiero świadomy retry
użytkownika rozpoczyna nową retry generation z nowym budżetem. Dla YouTube
wywołuje F-02 poza transakcją z `ManagedExport` oraz stabilnym ID operacji;
limit i awaria kończą się przed refresh/OAuth i przed pierwszym write. Wszystkie
checkpointy i terminalizacja używają CAS po operacji+generacji.

#### 3. Create, marker recovery i checkpoint celu

**Pliki**: `app/Actions/ManagedAccountExport/ResolveManagedExportTarget.php`,
`app/Actions/ManagedAccountExport/ReconcileManagedExport.php`,
`app/Models/PlaylistExportTargetAttempt.php`

**Cel**: Zapobiec drugiemu create po utraconej odpowiedzi i zapisać locator
zanim jakakolwiek pozycja trafi do celu.

**Kontrakt**: Znane ID aktywnej próby zawsze prowadzi do inspect tego ID; 404 daje
`recreate_required`, nigdy fallback do markera. Bez ID najpierw wykonywany jest
pełny scan markera. Create jest dozwolony tylko przy kompletnym `none` i braku
wcześniejszej próby. Pewny wynik jest walidowany i commitowany natychmiast.
Timeout/5xx/invalid response po create ustawia nieznany wynik; dalsze automaty
wykonują tylko scan, a po wyczerpaniu przechodzą do
`manual_recovery_required`. Poprzednie `abandoned` próby nie są automatycznie
wybierane jako bieżący cel, ale zachowują marker i ewentualny locator dla
późniejszego recovery oraz cleanupu.

#### 4. Exact-state reconciliation i terminalizacja

**Pliki**: `app/Actions/ManagedAccountExport/ReconcileManagedExport.php`,
`app/Actions/ManagedAccountExport/MaterializeManagedExportPlaylist.php`,
`app/Models/PlaylistExport.php`, `app/Models/ExportOperation.php`,
`app/Models/Playlist.php`, `app/Actions/Playlists/ImportPlaylist.php`,
`app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php`,
`app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php`,
`app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php`,
`app/Jobs/RefreshYouTubePlaylistMetadata.php`

**Cel**: Zapewnić, że sukces oznacza dokładne metadane i uporządkowaną
zawartość manifestu, a partial retry używa tego samego celu.

**Kontrakt**: Po zapisaniu ID worker ponownie weryfikuje owner/marker/visibility,
rekoncyliuje pełny manifest i po stabilnym exact verify w krótkiej transakcji
idempotentnie tworzy albo aktualizuje dokładnie jeden docelowy `Playlist` oraz
jego uporządkowane pozycje z zamrożonych metadanych review. Przy kolizji
istniejącego bankowego rekordu o tym samym providerowym ID przejmuje go dopiero
po walidacji providera i technicznego ownera; niezgodność kończy się fail-closed
bez nadpisania. W tej samej transakcji ustawia `origin=managed_target`, wiąże
rekord przez `target_playlist_id` i dopiero wtedy publikuje `succeeded`.
Snapshot zapisuje provider, konto, provider playlist ID, canonical URL, nazwę i
opis zarządzanej kopii. Source import/reimport oraz YouTube refresh/purge jawnie
filtrują `origin=imported`, dzięki czemu target nie przejmuje roli źródła prawdy
dla wykonania ani driftu. Awaria
materializacji pozostawia operację bez `succeeded`, a retry ponownie używa tego
samego celu. Awaria przed możliwą mutacją daje `failed`; znany locator lub
możliwa mutacja daje `partial_failed`. Retry zachowuje operation ID, marker,
target ID i rezerwację F-02. Provider-controlled tekst nie trafia do persistence
ani UX.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Confirm managed tworzy jedną operację i job po commit, double submit zwraca
  tę samą operację, linked pozostaje handoffem S-07, a rollback nie enqueue'uje:
  `php artisan test tests/Feature/ManagedAccountExport/StartManagedExportTest.php`.
- Test worker orchestration dowodzi kolejności commit→admission→access→write,
  zera requestów przy odmowie F-02 oraz write-once provider ID:
  `php artisan test tests/Feature/ManagedAccountExport/RunManagedExportTest.php`.
- Test materializacji dowodzi, że dopiero stabilny sukces tworzy dokładnie jeden
  docelowy `Playlist` z uporządkowanym snapshotem i relacją do źródła, a retry po
  awarii transakcji nie tworzy duplikatu. Pokrywa też bezpieczne przejęcie
  istniejącego importu o zgodnym providerze i ownerze oraz fail-closed dla
  niezgodnej kolizji:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportMaterializationTest.php`.
- Fault matrix pokrywa awarię przed write, po create, po każdej pozycji,
  niejednoznaczny create, inconclusive scan, wiele markerów, 404 i exact retry
  tej samej playlisty.
- PostgreSQL dowodzi jednego startu, jednego claimu, jednej kanonicznej relacji,
  odporności CAS, trwałego limitu automatycznych claimów mimo nowych jobów oraz
  tej samej rezerwacji F-02 pod wyścigiem:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportPostgresTest.php`.
- Pełna regresja S-05 i F-02 przechodzi bez realnej sieci:
  `php artisan test tests/Feature/ExportMatchReview tests/Feature/Integrations/YouTubeWriteAdmission`.

#### Weryfikacja ręczna

- Przegląd sekwencji potwierdza brak transakcji podczas OAuth/HTTP oraz commit
  admission i locatora przed odpowiednimi mutacjami.
- Kontrolowane fake walkthrough potwierdza ten sam operation ID, marker,
  provider ID i F-02 reservation po częściowym retry.

**Uwaga implementacyjna**: Po fazie zatrzymaj się do ręcznej akceptacji
sekwencji mutacji i dowodu współbieżności PostgreSQL.

---

## Phase 4: Retry, status i komunikacja

### Przegląd

Faza wystawia trwały cykl życia w review i banku, dodaje bezpieczne akcje retry
oraz odtworzenia i odzyskuje osierocone zadania.

### Wymagane zmiany

#### 1. Owner-scoped retry i jawne odtworzenie

**Pliki**: `routes/web.php`,
`app/Http/Controllers/ManagedExportController.php`,
`app/Actions/ExportReviews/StartExportReview.php`,
`app/Actions/ManagedAccountExport/RetryManagedExport.php`,
`app/Actions/ManagedAccountExport/RequestManagedTargetRecreation.php`

**Cel**: Pozwolić użytkownikowi bezpiecznie ponowić tę samą operację albo
świadomie zaakceptować nowy link po utracie/nieznanym wyniku celu.

**Kontrakt**: Obie akcje są auth+verified, CSRF, owner-scoped i throttled.
Retry działa tylko dla dozwolonych terminalnych stanów i respektuje
`retry_available_at`; zachowuje locator, marker i operation ID. Recreate wymaga
osobnego potwierdzenia skutków, atomowo oznacza poprzednią próbę celu jako
`abandoned`, tworzy nową generację z nowym markerem i dopiero wtedy pozwala na
nowy create w tej samej logicznej operacji. Poprzedni marker i locator nie są
nadpisywane. Aktywna lub częściowa operacja blokuje przygotowanie kolejnego
review dla tego samego source+provider+target account niezależnie od zmiany
fingerprintu. `StartExportReview` wykonuje tę kontrolę pod istniejącą blokadą
playlisty, więc ukrycie akcji w banku nie jest jedyną ochroną, a bezpośredni POST
kończy się bez utworzenia review i joba.

#### 2. Status na ekranie review

**Pliki**: `app/Livewire/ExportReviewPanel.php`,
`resources/views/livewire/export-review-panel.blade.php`,
`app/Http/Controllers/PlaylistExportReviewController.php`

**Cel**: Po potwierdzeniu płynnie przejść z review do wykonania bez nowego
modelu nawigacji.

**Kontrakt**: Polling działa tylko dla `queued|processing`, aria-live ogłasza
wyłącznie zmianę. Widok mapuje statusy na dokładne sformułowania FR-011,
wyświetla bezpieczną przyczynę i cooldown. Sukces pokazuje link, właściciela
„konto zarządzane przez music-map” i miejsce edycji; partial pokazuje wymagany
komunikat o aktualizacji tej samej playlisty. Manual/recreate state wyjaśnia
ryzyko zmiany linku przed przyciskiem.

#### 3. Bounded status w banku

**Pliki**: `app/Http/Controllers/BankController.php`,
`resources/views/bank/index.blade.php`,
`app/Http/Controllers/PlaylistEditingController.php`,
`app/Http/Controllers/PlaylistReimportController.php`,
`app/Actions/ExportReviews/StartExportReview.php`

**Cel**: Pokazać najnowszą aktywną lub zakończoną operację i uniemożliwić
konkurencyjny start bez ładowania całej historii.

**Kontrakt**: Query dla managed ładuje najwyżej potrzebny aktywny i latest
zakończony rekord na użytkownika+playlistę+provider z utrwalonych operacji, bez
filtrowania historii po bieżącym runtime account ID. Karta źródła prowadzi do
statusu, sukcesu lub retry, opierając właściciela i link na zapisanym
`target_account_id`; bieżąca konfiguracja konta służy wyłącznie do rozpoczęcia
nowego review. Jeśli historyczne konto różni się od bieżącego, link i status
pozostają widoczne, ale retry/recreate działa fail-closed, dopóki dostęp
techniczny nie potwierdzi tego samego konta. Po sukcesie karta pokazuje
nawigowalną, read-only relację do osobnego docelowego snapshotu. Dla
`origin=managed_target` nie pokazuje edycji, reimportu ani rozpoczęcia eksportu,
a odpowiadające bezpośrednie requesty kończą się fail-closed. Nie oferuje nowego review
dla tego samego fizycznego celu podczas `queued`, `processing`,
`partial_failed`, `manual_recovery_required` albo `recreate_required` i nie
dubluje docelowej playlisty przy ponownym renderze.

#### 4. Powiadomienie i odzyskiwanie dispatchu

**Pliki**: `app/Jobs/SendManagedExportCompletedNotification.php`,
`app/Notifications/ManagedExportCompleted.php`,
`resources/views/mail/managed-export-completed.blade.php`,
`app/Console/Commands/RecoverManagedExportOperations.php`, `routes/console.php`

**Cel**: Niezawodnie wysłać wiadomość dla terminalnej generacji po operacji
trwającej co najmniej 60 sekund i odzyskać lukę commit–enqueue, z semantyką
at-least-once oraz best-effort deduplikacją.

**Kontrakt**: Powiadomienie jest osobnym unikalnym jobem; znacznik wysłania
powstaje dopiero po udanym notify i zawiera generation. Zapobiega to duplikatom
w normalnym retry, ale crash po przyjęciu wiadomości przez transport i przed
checkpointem może spowodować ponowną dostawę; plan nie obiecuje exactly-once.
Recovery co minutę
chunkuje bounded `queued` starsze od krótkiego progu oraz `processing` bez
heartbeat starsze niż pełny lease. Przed enqueue atomowo claimuje wygasłą
dzierżawę publikacji. `RunManagedExport` nie używa dispatch-time
`ShouldBeUnique`: każdy dostarczony payload przechodzi przez bounded
`WithoutOverlapping`, atomowy claim operacji i CAS po retry/attempt generation,
a duplikat bez prawa do claimu kończy się bez OAuth i requestu providera.
Powtarzany scheduler nie publikuje ponownie przed wygaśnięciem trwałej
dzierżawy; backlog może zawierać zduplikowany payload, ale nie prowadzi on do
równoległej mutacji. Wygaśnięcie dzierżawy pozwala odzyskać awarię między
claimem i enqueue, a nowy job nie resetuje trwałego budżetu automatycznych
claimów. Test awarii enqueue oraz crashu po udanym enqueue przed checkpointem
dowodzi, że committed intent zostaje odzyskany bez nowej operacji, playlisty i
rezerwacji, a wielokrotna dostawa nie wykonuje drugiego provider requestu.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Feature tests pokrywają owner scoping, CSRF, throttle, cooldown, blokadę
  drugiego startu również przez bezpośredni POST po zmianie fingerprintu, retry
  samej operacji oraz jawne recreate zachowujące wszystkie wcześniejsze
  generacje markerów i locatorów:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportRouteTest.php`.
- Livewire tests dowodzą polling stop, exact copy FR-011, linku, ownera,
  instrukcji i stanów manual/recreate:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportPanelTest.php`.
- Bank query pozostaje bounded, pokazuje właściwą bieżącą operację i zachowuje
  historyczny status oraz link po zmianie runtime technical account ID, bez
  udostępnienia retry dla niepotwierdzonego starego konta. Test dowodzi też, że
  target snapshot jest read-only i że bezpośrednie requesty edit, reimport oraz
  start review są odrzucane:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportBankTest.php`.
- Powiadomienie pokrywa próg 60 sekund, retry przed udanym transportem,
  deduplikację po zapisanym checkpointcie, jawne okno możliwego duplikatu po
  crash-after-send oraz bezpieczny link:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportNotificationTest.php`.
- Recovery testuje crash między commit i enqueue, backlog z wielokrotnym
  przebiegiem schedulera, crash po enqueue przed checkpointem publikacji,
  wielokrotną dostawę tego samego payloadu, wyczerpanie trwałego budżetu claimów
  oraz stale processing bez przedwczesnego równoległego workera:
  `php artisan test tests/Feature/ManagedAccountExport/ManagedExportRecoveryTest.php`.

#### Weryfikacja ręczna

- Klawiaturowe i mobilne przejście review→status→retry/recreate jest czytelne,
  zachowuje fokus i nie ogłasza powtarzalnie niezmienionego statusu.
- Treść sukcesu i błędów jednoznacznie rozróżnia właściciela, miejsce edycji,
  zachowanie linku oraz ryzyko jawnego odtworzenia.

**Uwaga implementacyjna**: Po fazie zatrzymaj się do ręcznej akceptacji UX,
copy i zachowania dwóch kart przeglądarki.

---

## Phase 5: Dowód całości i wydanie

### Przegląd

Faza składa pełny fake workflow, uruchamia bramki repozytorium i zapisuje
kontrolowany live smoke obu technicznych kont bez sekretów.

### Wymagane zmiany

#### 1. Pełny fake workflow i fault injection

**Pliki**: `tests/Feature/ManagedAccountExport/ManagedExportWorkflowTest.php`,
`tests/Feature/ManagedAccountExport/ManagedExportPostgresTest.php`

**Cel**: Udowodnić zachowanie od ready review do sukcesu i po każdym istotnym
punkcie awarii, bez realnej sieci.

**Kontrakt**: Macierz obejmuje oba providery, playlistę z duplikatem, limit 20,
quota refusal, awarię przed write, ambiguous create, częściowy item write,
retry tego samego ID, stale worker, dispatch loss, provider deletion oraz dwa
równoległe potwierdzenia/retry. Przypadek recreate po ambiguous create dowodzi,
że nowa generacja nie usuwa markera ani locatora poprzedniej próby. Sukces
materializuje jedną docelową playlistę bankową, a wcześniejsze awarie nie tworzą
częściowego snapshotu. Macierz dowodzi również, że import tego samego linku nie
nadpisuje targetu, a cykl YouTube refresh/purge pomija `managed_target` po
przekroczeniu progów 28/30 dni. PostgreSQL uruchamia rzeczywiste blokady i F-02,
ale nadal używa fake gatewayów.

#### 2. Kontrakt źródła i bramki jakości

**Pliki**: `scripts/verify-source-contract`, `tests/Unit/SourceContractTest.php`,
`.github/workflows/ci.yml`

**Cel**: Włączyć wszystkie stabilne pliki modułu i dowody PostgreSQL do
istniejących bramek bez sekretów i prawdziwych requestów.

**Kontrakt**: Manifest źródła obejmuje nowe pliki PHP, PostgreSQL job uruchamia
fresh migrations oraz pełną suite, a standardowe CI nie potrzebuje technical
credentials.

#### 3. Artefakt live smoke

**Plik**: `context/changes/managed-account-export/reviews/manual-verification.md`

**Cel**: Zapisać pozbawiony sekretów dowód dla dokładnego kandydata i obu kont
technicznych.

**Kontrakt**: Artefakt rejestruje tylko commit/release, PASS/FAIL, widoczność,
stały link, round-trip markera, create/update tego samego provider ID,
częściowe przerwanie i retry tej samej rezerwacji oraz cleanup. Nie zapisuje
account ID, playlist ID, tokenów, payloadów ani mechaniki PaaS.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełny fake workflow oraz fault matrix przechodzą dla Spotify i YouTube:
  `php artisan test tests/Feature/ManagedAccountExport`.
- Pełna suite przechodzi: `composer test`.
- Formatowanie PHP przechodzi: `vendor/bin/pint --test`.
- Produkcyjny frontend buduje się: `npm run build`.
- Source contract przechodzi dla worktree: `sh scripts/verify-source-contract --worktree`.
- Audyty zależności przechodzą: `composer audit --locked --no-interaction` oraz
  `npm audit --audit-level=high`.
- Hosted/disposable PostgreSQL wykonuje `migrate:fresh`, pełną suite i wszystkie
  niewyłączone testy współbieżności na dokładnym kandydacie.

#### Weryfikacja ręczna

- Spotify live smoke potwierdza `public=false`, dostęp przez stały link,
  niewidoczność na profilu, marker, create/update tego samego ID i cleanup.
- YouTube live smoke potwierdza `unlisted`, stały link, marker, create/update
  tego samego ID, partial retry z jedną rezerwacją F-02 i cleanup.
- Przegląd logów, failed jobs, maila i artefaktu dowodzi braku sekretów,
  provider IDs, account IDs i surowych odpowiedzi.
- Operator potwierdza dokładną opublikowaną wersję publicznego kontraktu rotacji
  technical refresh tokenu dla zwykłych jobów oraz jej działanie w live smoke;
  bez zgodnego kontraktu aplikacja pozostaje fail-closed przed użyciem access
  tokenu i S-06 nie jest gotowe do wydania.

**Uwaga implementacyjna**: Nie uznawaj S-06 za gotowe przed zielonym
PostgreSQL i kontrolowanym live smoke obu providerów na dokładnym kandydacie.

## Strategia testowania

### Testy jednostkowe

- DTO, enumy, markery, metadane i zamknięte mapowanie błędów.
- Dokładne requesty HTTP, scope/identity, widoczność, paginacja i odpowiedzi
  obu gatewayów.
- Spotify full replace oraz YouTube stable-read reconciliation z duplikatami.
- Brak tokenów i provider-controlled tekstu w serializacji oraz logach.

### Testy integracyjne

- Atomowe confirm→operation→afterCommit i odzyskanie utraconego dispatchu.
- Admission przed pierwszym write, bezterminowe same-key retry oraz brak refundu.
- Checkpoint provider ID przed itemami, exact final verify i CAS attempts.
- Owner-scoped UI, throttled retry/recreate, bounded bank query i powiadomienia.
- Materializacja osobnego docelowego snapshotu dopiero po exact verify.
- PostgreSQL races dla double confirm, job claim, canonical relation i F-02.

### Kroki testowania ręcznego

1. Na dedykowanym koncie technicznym utworzyć zarządzany eksport Spotify i
   potwierdzić nazwę, opis, prywatność profilową, link i owner copy.
2. Powtórzyć na YouTube i potwierdzić `unlisted` oraz jedno dopuszczenie F-02.
3. Zmienić bank, wykonać nowe review i sprawdzić aktualizację tego samego celu.
4. Przerwać zapis pozycji, ponowić i potwierdzić exact kolejność oraz brak
   duplikatu playlisty.
5. Zasymulować nieznany create i brak celu, potwierdzając brak automatycznego
   drugiego create oraz jawną akcję odtworzenia.
6. Sprawdzić review i bank na urządzeniu mobilnym, klawiaturą i w dwóch kartach.

## Uwagi dotyczące wydajności

Zakres pozostaje ograniczony do 20 pozycji. Spotify może zastąpić cały manifest
jednym requestem; YouTube wykonuje sekwencyjne zapisy, ale unika ich, gdy exact
stan już istnieje. Wszystkie listy są paginowane i jawnie ograniczone; dojście
do limitu bez końca listy daje `inconclusive`, nie zgodę na create. Worker i
lease pozostają odpowiednio 450/510 sekund, a UI pozwala odejść po minucie.

## Uwagi dotyczące migracji

Powyższe migracje są addytywne i mogą zostać wdrożone przed aktywacją kodu. Przed
pierwszym realnym eksportem zwykły rollback może usunąć nowe puste tabele;
potem locatorów nie wolno niszczyć bez jawnej migracji danych, ponieważ są
potrzebne do retry i S-10. Kolejność rollbacku to operations i target attempts
przed exports.
Restrictive FK celowo powodują fail-closed przy surowym usuwaniu użytkownika lub
playlisty, dopóki przyszła orkiestracja S-10 nie usunie zasobu zewnętrznego.

## Referencje

- Zakres produktu: `context/foundation/prd.md` — US-01, FR-010, FR-011,
  NFR-001, NFR-002, NFR-003 i NFR-006.
- Wycinek i zależności: `context/foundation/roadmap.md:169`.
- Ustalone decyzje własności i retry: `context/foundation/shape-notes.md`.
- Handoff S-05: `context/archive/2026-09-14-export-match-review/plan.md` oraz
  `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php`.
- Admission F-02: `context/archive/2026-09-14-youtube-write-admission/plan.md`
  oraz `app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php`.
- Publiczna granica PaaS: `AGENTS.md` i
  `context/archive/2026-09-12-platform-access-readiness/plan.md`.
- Zewnętrzny blocker: oczekujący publiczny kontrakt rotacji dla zwykłych
  workerów; po publikacji wpisać dokładną wersję i trwałe publiczne źródło.
- Spotify Create Playlist:
  `https://developer.spotify.com/documentation/web-api/reference/create-playlist`.
- Spotify Current User's Playlists:
  `https://developer.spotify.com/documentation/web-api/reference/get-a-list-of-current-users-playlists`.
- Spotify playlist visibility:
  `https://developer.spotify.com/documentation/web-api/concepts/playlists`.
- YouTube playlists insert/list/update:
  `https://developers.google.com/youtube/v3/docs/playlists/insert`,
  `https://developers.google.com/youtube/v3/docs/playlists/list`,
  `https://developers.google.com/youtube/v3/docs/playlists/update`.
- YouTube quota costs:
  `https://developers.google.com/youtube/v3/determine_quota_cost`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Trwały kontrakt operacji i relacji

#### Automated

- [x] 1.1 Zweryfikować migracje i ograniczenia na SQLite — cd4fc50
- [x] 1.2 Zweryfikować modele, enumy, relacje i write-once locator — cd4fc50
- [x] 1.3 Udowodnić unikalność operacji i kopii na PostgreSQL — cd4fc50
- [x] 1.4 Uruchomić regresję playlist, review i F-02 — cd4fc50

#### Manual

- [ ] 1.5 Potwierdzić model własności i restrykcyjne usuwanie
- [ ] 1.6 Potwierdzić brak sekretów i danych osobowych w persistence

### Phase 2: Dostęp techniczny i gatewaye providerów

#### Automated

- [x] 2.1 Zweryfikować efemeryczny dostęp techniczny i rotację — 27862a5
- [x] 2.2 Zweryfikować marker i deterministyczne metadane — 27862a5
- [x] 2.3 Zweryfikować kontrakt Spotify przez fake HTTP — 27862a5
- [x] 2.4 Zweryfikować kontrakt YouTube przez fake HTTP — 27862a5
- [x] 2.5 Uruchomić regresję F-01 i Google login — 27862a5

#### Manual

- [ ] 2.6 Potwierdzić publiczną granicę PaaS
- [ ] 2.7 Potwierdzić owner, marker, visibility i pagination guards

### Phase 3: Atomowy start i odporne wykonanie

#### Automated

- [x] 3.1 Zweryfikować atomowy start i idempotentne potwierdzenie — acd71ff
- [x] 3.2 Zweryfikować admission-before-write i checkpoint locatora — acd71ff
- [x] 3.3 Pokryć fault matrix create, scan, item write i retry — acd71ff
- [x] 3.4 Udowodnić claim, CAS, canonical relation i F-02 na PostgreSQL — acd71ff
- [x] 3.5 Uruchomić pełną regresję S-05 i F-02 — acd71ff
- [x] 3.8 Zweryfikować atomową i idempotentną materializację docelowej playlisty — acd71ff

#### Manual

- [ ] 3.6 Potwierdzić sekwencję transakcji i providerowych mutacji
- [ ] 3.7 Potwierdzić ten sam operation, marker, target i admission po retry

### Phase 4: Retry, status i komunikacja

#### Automated

- [x] 4.1 Zweryfikować owner-scoped retry, recreate i blokadę drugiego startu
- [x] 4.2 Zweryfikować polling i dokładne komunikaty FR-011
- [x] 4.3 Zweryfikować bounded status operacji w banku
- [x] 4.4 Zweryfikować niezawodne powiadomienie per generation
- [x] 4.5 Zweryfikować odzyskanie utraconego dispatchu i stale processing

#### Manual

- [ ] 4.6 Potwierdzić dostępność i responsywność statusu oraz retry
- [ ] 4.7 Potwierdzić copy właściciela, linku i jawnego odtworzenia

### Phase 5: Dowód całości i wydanie

#### Automated

- [ ] 5.1 Uruchomić pełny fake workflow i fault matrix
- [ ] 5.2 Uruchomić pełną suite PHPUnit
- [ ] 5.3 Zweryfikować formatowanie Pint
- [ ] 5.4 Zbudować produkcyjny frontend
- [ ] 5.5 Zweryfikować source contract
- [ ] 5.6 Uruchomić audyty zależności
- [ ] 5.7 Udokumentować zielony hosted lub disposable PostgreSQL

#### Manual

- [ ] 5.8 Potwierdzić Spotify live smoke i cleanup
- [ ] 5.9 Potwierdzić YouTube live smoke, F-02 i cleanup
- [ ] 5.10 Potwierdzić brak sekretów oraz providerowych identyfikatorów w artefaktach
- [ ] 5.11 Potwierdzić opublikowany kontrakt rotacji technical refresh tokenu dla workerów
