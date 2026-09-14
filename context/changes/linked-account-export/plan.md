# Eksport na powiązane i zarządzane konto — plan implementacji

## Przegląd

Implementujemy jeden silnik eksportu dla wycinków S-07 i S-06. Po świadomym
potwierdzeniu zamrożonego manifestu aplikacja utworzy albo zaktualizuje osobną
playlistę na powiązanym koncie użytkownika lub koncie technicznym `music-map`,
utrwali relację źródło–eksport i pokaże jednoznaczny status, właściciela oraz
stały link.

Operacja ma być odporna na podwójne wysłanie, równoległe workery, odłączenie
konta i częściowe awarie providera. YouTube musi uzyskać wspólne dopuszczenie
F-02 przed pierwszą mutacją. Ponowienie zawsze używa tej samej logicznej
operacji i zapisanego ID celu; nie wykonuje ponownego matchingu.

Ścieżka managed zależy od będącej w przygotowaniu, wersjonowanej publicznej
capability PaaS dla technicznych zapisów wykonywanych przez zwykły worker.
Phase 1–2 mogą powstać niezależnie, lecz Phase 3 nie może rozpocząć się przed
publikacją kontraktu i wpisaniem jego dokładnej nazwy oraz wersji do tego planu.
Po ukończeniu Phase 2 cała zmiana pozostaje formalnie wstrzymana: nie wdrażamy
częściowo wspólnej orkiestracji ani samej ścieżki linked. Dalsza implementacja
rozpoczyna się dopiero po zaliczeniu ręcznej bramki 2.10.

## Analiza stanu obecnego

S-04 zapewnia konta streamingowe, wymagane zakresy OAuth i granicę
`WithStreamingAccess`, która udostępnia token wyłącznie wewnątrz synchronicznego
callbacku. S-05 rozwiązuje docelową tożsamość, przygotowuje dopasowania i po
potwierdzeniu zwraca niezmienny `ConfirmedExportManifest`. F-02 dostarcza
trwałe, globalne dopuszczenie zapisów YouTube dla typów `LinkedExport` i
`ManagedExport`.

Przepływ kończy się dziś na ustawieniu review na `confirmed`. Kontroler i
Livewire ignorują zwrócony manifest, nie istnieją klienci zapisu playlist,
trwała operacja wykonawcza, relacja źródło–eksport ani widok wyniku. Model
`Playlist` przechowuje providerowe ID, URL, metadane i uporządkowane pozycje,
więc może reprezentować osobną kopię docelową po dodaniu jawnej relacji.

Najważniejsze ograniczenie API dotyczy niejednoznacznego wyniku `create`.
Spotify i YouTube nie dokumentują klucza idempotencji dla tworzenia playlist.
Wybrana strategia zapisuje stabilny marker operacji w opisie i po utracie
odpowiedzi skanuje wszystkie playlisty należące do docelowego konta. Dokładnie
jeden wynik zostaje przyjęty; zero, wiele wyników albo niepełny skan zatrzymują
automatyczne tworzenie i wymagają bezpiecznego odzyskania.

## Pożądany stan końcowy

- Potwierdzenie review atomowo tworzy lub odzyskuje dokładnie jedną operację i
  po commit kieruje użytkownika do jej stanu.
- Pierwszy eksport tworzy osobny rekord `Playlist` dla zasobu platformowego i
  relację do źródła; kolejne zatwierdzone eksporty na tę samą parę
  provider–konto aktualizują wyłącznie zapisane providerowe ID.
- Spotify tworzy cel z `public: false`, YouTube z `privacyStatus: unlisted`;
  wynik pokazuje właściciela i stały link zgodnie z możliwościami platformy.
- Eksport doprowadza zawartość celu do dokładnej kolejności manifestu, łącznie z
  powtórzeniami, o ile provider je obsługuje. Jeżeli rzeczywisty smoke potwierdzi
  ograniczenie YouTube dla powtórzeń, powoduje ono czytelną odmowę przed mutacją,
  nigdy cichą deduplikację.
- Użytkownik widzi jeden z pięciu stanów FR-011, może bezpiecznie ponowić awarię
  przejściową lub częściową i otrzymuje powiadomienie, gdy operacja trwała co
  najmniej minutę.
- Automatyczne testy dowodzą własności, idempotencji, kolejności dopuszczenia
  YouTube i odporności na współbieżność; ręczne testy używają obu rzeczywistych
  providerów dla trybu linked i managed.

### Kluczowe odkrycia

- Potwierdzenie jest owner-scoped, blokuje review i playlistę oraz zwraca frozen
  manifest, ale nie rozpoczyna zapisu
  (`app/Actions/ExportReviews/ConfirmExportReview.php:26`).
- Manifest przechowuje uporządkowane docelowe ID/URI i docelową tożsamość, lecz
  nie zamraża nazwy ani opisu playlisty
  (`app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:13`).
- `WithStreamingAccess` sprawdza właściciela, zakresy i wersję poświadczeń oraz
  nie pozwala serializować tokenu (`app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php:27`).
- Dopuszczenie YouTube wymaga wcześniej utrwalonego stabilnego ID, wywołania
  poza transakcją konsumenta i tego samego klucza przy każdym retry
  (`README.md:150`, `app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php:29`).
- Bank ładuje aktywne lub ponawialne review, ale pomija `confirmed`, więc po
  potwierdzeniu nie ma trwałego statusu eksportu (`app/Http/Controllers/BankController.php:71`).
- Spotify udostępnia obecnie tworzenie przez `POST /me/playlists` i pełne
  zastąpienie przez `PUT /playlists/{id}/items`; starsze ścieżki `/tracks` nie
  mogą być używane.
- YouTube aktualizuje zawartość przez osobne zasoby `playlistItems`; dokładne
  odtworzenie wymaga odczytu occurrence ID, minimalnego zestawu zmian i końcowej
  weryfikacji kolejności.

## Czego NIE robimy

- Nie implementujemy synchronizacji źródła S-08, wykrywania driftu S-09 ani
  usuwania konta S-10.
- Nie wykonujemy eksportu do tej samej pary provider–konto, z której pochodzi
  źródło; pozostaje obowiązująca walidacja S-05.
- Nie rematchujemy utworów po potwierdzeniu i nie czytamy nowszej zawartości
  banku do już uruchomionej operacji.
- Nie scalamy ręcznych zmian celu. Nowe świadome potwierdzenie zastępuje jego
  zawartość dokładnym manifestem.
- Nie tworzymy automatycznie następcy playlisty usuniętej u providera; wymagany
  jest nowy review i potwierdzenie.
- Nie deduplikujemy po cichu powtórzonych utworów i nie obiecujemy wsparcia dla
  playlist powyżej 20 pozycji.
- Nie rozszerzamy `ExportReviewStatus` o cykl wykonania i nie wynosimy tokenów z
  granic dostępu.
- Nie kopiujemy do aplikacji mechaniki Managera. Dla kont technicznych używamy
  wyłącznie publicznego kontraktu runtime/rotacji poświadczeń i traktujemy
  Manager jako zewnętrzny PaaS.
- Nie budujemy panelu administracyjnego do ręcznego wpisywania providerowego ID.
  Recovery wymagające człowieka instruuje użytkownika, by uporządkował zasoby u
  providera i ponowił sam skan tej samej operacji.

## Podejście do implementacji

Rozdzielamy snapshot decyzyjny, logiczną operację i trwałą relację celu.
`ExportReview` pozostaje zakończonym artefaktem matchingu. `ExportOperation`
opisuje jedną świadomie zatwierdzoną próbę i jest stabilnym kluczem retry oraz
dopuszczenia YouTube. `PlaylistExportLink` łączy źródłową `Playlist` z osobnym
docelowym rekordem `Playlist`, dzięki czemu następny świeży review aktualizuje
ten sam providerowy zasób i przygotowuje dane dla S-09.

Provider-neutralny port zapisu przyjmuje efemeryczny access token, zamrożone
metadane, marker i uporządkowane ID/URI. Adaptery Spotify i YouTube wykonują
bounded validation oraz zwracają wyłącznie typowany, pozbawiony sekretów wynik.
Orkiestrator odpowiada za claim operacji, dostęp linked/managed, admission,
retry, trwałe checkpointy i mapowanie stanów. Żaden lock bazodanowy nie jest
utrzymywany podczas OAuth ani żądania HTTP.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Stabilne `operation_id` i snapshot operacji muszą zostać zatwierdzone w bazie
przed dispatch i przed dopuszczeniem YouTube. Admission odbywa się poza
transakcją konsumenta, bezpośrednio przed pierwszą mutacją; odczyty recovery lub
preflight mogą nastąpić wcześniej. Po niejednoznacznym `create` kolejny job może
wyłącznie wykonać kompletny skan po markerze — nie może ponownie wywołać
`create`, dopóki operacja pozostaje w stanie recovery. Gdy skan nadal zwraca
zero lub wiele wyników, użytkownik może dopiero po jawnym potwierdzeniu, że
sprawdził i usunął ewentualne orphan copies u providera, porzucić recovery;
operacja staje się terminalnym `failed`, zwalnia active key i pozwala wykonać
nowy review.

### Sekwencjonowanie stanu

`provider_mutation_started_at` jest utrwalane bezpośrednio przed pierwszą
mutacją. Po tej granicy timeout albo niejednoznaczna odpowiedź daje stan
`incomplete`; przed nią daje `failed`. Providerowe ID i relacja celu muszą być
utrwalone natychmiast po potwierdzonym lub odzyskanym `create`, zanim rozpocznie
się modyfikacja elementów.

### Ograniczenia wydajności

YouTube nalicza koszt każdej mutacji elementu, więc adapter nie może rutynowo
usuwać i odtwarzać całej playlisty. Najpierw odczytuje maksymalnie 20 pozycji,
wylicza minimalny deterministyczny plan zmian, wykonuje go sekwencyjnie i na
końcu porównuje dokładną kolejność. Logical admission F-02 pozostaje nadrzędną
bramą, ale nie zastępuje obsługi odpowiedzi providerowego limitu kwoty.

## Phase 1: Trwały model eksportu i atomowy start

### Przegląd

Dodajemy provider-neutralny model operacji oraz relację źródło–cel. Potwierdzenie
review zamraża również metadane, rozwiązuje konflikt aktywnej operacji i
dispatchuje wykonanie dopiero po udanym commit.

### Wymagane zmiany

#### 1. Schemat relacji źródło–eksport

**Pliki**:

- `database/migrations/<timestamp>_add_role_to_playlists_table.php`
- `database/migrations/<timestamp>_create_playlist_export_links_table.php`

**Cel**: Utrwalić jednoznaczną relację między bankowym źródłem i osobną
playlistą platformową, bez przeciążania historii operacji.

**Kontrakt**: `playlists.role` ma zamknięte wartości `source|export_target`, a
wszystkie istniejące rekordy i nowe importy otrzymują `source`. Tabela linków
zawiera ownera, `source_playlist_id`, `target_playlist_id`, provider, typ
właściciela, stabilne `target_account_id`, `retired_at` oraz nullable unique
`active_key` wyprowadzony z relacji. Aktywny klucz wymusza jeden bieżący cel dla
źródła/provider/konta, a po potwierdzonym 404 zostaje wyczyszczony razem z
ustawieniem `retired_at`, zachowując historię i pozwalając świeżemu review
utworzyć następcę. `target_playlist_id` jest unikalny; źródło ma rolę `source`,
cel rolę `export_target`, oba należą do tego samego użytkownika i nie mogą być
tym samym rekordem.

#### 2. Schemat operacji eksportu

**Plik**: `database/migrations/<timestamp>_create_export_operations_table.php`

**Cel**: Zapisać logiczną operację, jej frozen input, checkpointy, wynik i stan
powiadomienia przed uruchomieniem workera.

**Kontrakt**: Jedno `export_review_id` ma najwyżej jedną operację; UUID
`operation_id` jest unikalny i mieści się w kontrakcie F-02. Rekord zachowuje
ownera, źródło, nullable link i konto, dokładną docelową tożsamość, snapshot
nazwy/opisu/fingerprintu, status, zamknięty failure code, liczbę prób,
`provider_mutation_started_at`, `started_at`, `completed_at` i
`notification_sent_at`. Nullable unique `active_key` blokuje dwa aktywne
manifesty dla tej samej relacji i jest czyszczony tylko po sukcesie albo stanie
terminalnym wymagającym nowego review.

#### 3. Modele, enumy i relacje Eloquent

**Pliki**:

- `app/Models/PlaylistExportLink.php`
- `app/Models/ExportOperation.php`
- `app/Enums/ExportOperationStatus.php`
- `app/Enums/ExportOperationFailure.php`
- `app/Models/Playlist.php`
- `app/Models/ExportReview.php`
- `app/Models/StreamingAccount.php`
- `app/Models/User.php`
- `app/Enums/PlaylistRole.php`
- `app/Actions/Playlists/ImportPlaylist.php`
- `app/Actions/Playlists/ReplaceImportedPlaylist.php`
- `app/Actions/Playlists/UpdateBankPlaylistItems.php`
- `app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php`
- `app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php`
- `app/Actions/ExportReviews/StartExportReview.php`
- `app/Integrations/PlaylistImport/ImportFailureCode.php`
- `app/Http/Requests/StartExportReviewRequest.php`
- `app/Http/Requests/ConfirmExportReviewRequest.php`
- `app/Http/Controllers/BankController.php`
- `app/Http/Controllers/PlaylistEditingController.php`
- `app/Http/Controllers/PlaylistReimportController.php`
- `app/Http/Controllers/PlaylistExportReviewController.php`
- `app/Livewire/PlaylistEditor.php`
- `app/Jobs/PrepareExportReview.php`
- `app/Jobs/RefreshYouTubePlaylistMetadata.php`
- `app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php`
- `database/factories/PlaylistExportLinkFactory.php`
- `database/factories/ExportOperationFactory.php`
- `tests/Feature/Exports/PlaylistExportTargetBoundaryTest.php`

**Cel**: Udostępnić jawne relacje i zamknięty cykl wykonania niezależny od
matchingu.

**Kontrakt**: Statusy domenowe to `queued`, `processing`, `transferred`, `failed`
i `incomplete`. Failure enum rozróżnia co najmniej admission limit/unavailable,
reconnect, missing scope, stale credential, rate/quota, temporary failure,
access denied, invalid response, target deleted, unsupported duplicate oraz
ambiguous create, incomplete recovery scan i recovery abandoned. Żadne pole nie
przechowuje tokenu ani surowego payloadu API. Model udostępnia jeden wspólny
source-only scope/assertion, egzekwowany również na granicach akcji wywoływanych
bezpośrednio przez joby lub testy. Wszystkie istniejące wejścia edycji,
reimportu, startu i przygotowania review oraz okresowego maintenance przyjmują
wyłącznie playlisty z rolą `source`; bank filtruje tę rolę już w Fazie 1.
Import zasobu providerowego, którego ID należy już do `export_target`, zwraca
zamkniętą odmowę i nie nadpisuje rekordu ani relacji. Kontrola jest powtarzana
wewnątrz transakcji przed replace/upsert, aby równoległe utworzenie targetu nie
pozwoliło go przejąć. Test granicy wywołuje bezpośrednio akcje edycji, review i
odświeżania oraz job maintenance, zamiast dowodzić reguły wyłącznie przez HTTP;
bank nie pokazuje targetu jako równorzędnej karty źródłowej.

#### 4. Atomowe rozpoczęcie operacji

**Pliki**:

- `app/Actions/Exports/StartExportOperation.php`
- `app/Jobs/ExecuteExportOperation.php`
- `app/Console/Commands/ReconcileQueuedExportOperations.php`
- `app/Actions/ExportReviews/ConfirmExportReview.php`
- `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php`
- `app/Http/Controllers/PlaylistExportReviewController.php`
- `app/Livewire/ExportReviewPanel.php`
- `routes/console.php`

**Cel**: Połączyć oba wejścia potwierdzenia z jednym idempotentnym startem i
wyeliminować okno między `confirmed` a trwałą operacją.

**Kontrakt**: Potwierdzenie pod istniejącymi lockami tworzy albo odzyskuje jedną
operację, kopiuje nazwę i opis należące do zatwierdzonego fingerprintu oraz
zwraca operation ID. Aktywna operacja tej samej relacji blokuje nowy manifest i
kieruje użytkownika do bieżącego stanu. `afterCommit` wykonuje pierwszą próbę
dispatchu, lecz rekord `queued` pozostaje trwałą intencją wykonania. Reconciler
uruchamiany co minutę ponownie dispatchuje operacje pozostające `queued` przez
co najmniej dwie minuty. `confirmed` review bez powiązanej operacji jest
rekordem historycznym sprzed uruchomienia silnika eksportu: jego replay nie
tworzy operacji ani nie wykonuje mutacji providera, lecz kieruje użytkownika do
utworzenia i świadomego zatwierdzenia świeżego review. Po wdrożeniu silnika
atomowa transakcja nie może utworzyć nowego `confirmed` bez operacji. Reconciler
obsługuje wyłącznie istniejące operacje. Duplikaty na granicy kolejki są
dozwolone i bezpieczne dzięki idempotentnemu atomic claimowi workera oraz
`WithoutOverlapping`; rollback transakcji nie pozostawia rekordu ani joba.

#### 5. Testy kontraktu danych i startu

**Pliki**:

- `tests/Feature/Exports/ExportOperationMigrationTest.php`
- `tests/Feature/Exports/StartExportOperationTest.php`
- `tests/Feature/Exports/ExportOperationDispatchRecoveryTest.php`
- `tests/Feature/ExportMatchReview/ConfirmExportReviewTest.php`

**Cel**: Udowodnić własność, unikalność, snapshot, idempotencję i prawdziwą
semantykę after-commit przed dodaniem sieci.

**Kontrakt**: Testy obejmują double submit, równoległy active key, zły owner,
review inne niż `confirmed`, otwartą transakcję, commit i rollback. Nie wystarczy
asercja pojedynczego push po powrocie z akcji. Osobny test symuluje crash po
commicie operacji, ale przed enqueue, uruchamia reconciler i dowodzi późniejszego
wykonania tej samej operacji. Reconciler ignoruje świeże oraz terminalne rekordy;
powtórny dispatch nie tworzy drugiej operacji ani drugiego providerowego celu.
Test cutover dowodzi również, że historyczne `confirmed` bez operacji nie może
jej utworzyć przez replay i wymaga świeżego review.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Migracje i testy modeli przechodzą na in-memory SQLite:
  `php artisan test tests/Feature/Exports/ExportOperationMigrationTest.php`.
- Test startu dowodzi jednego operation ID/joba, blokady aktywnego eksportu i
  dispatch dopiero po commit:
  `php artisan test tests/Feature/Exports/StartExportOperationTest.php tests/Feature/ExportMatchReview/ConfirmExportReviewTest.php`.
- Test odzyskania dispatchu dowodzi, że osierocone `queued` zostaje ponownie
  zakolejkowane, a świeże i terminalne operacje są pomijane:
  `php artisan test tests/Feature/Exports/ExportOperationDispatchRecoveryTest.php`.
- Test granicy roli dowodzi, że target nie może wejść do edycji, reimportu,
  nowego review ani maintenance, a import tego samego providerowego ID nie
  przejmuje rekordu:
  `php artisan test tests/Feature/Exports/PlaylistExportTargetBoundaryTest.php`.
- Formatowanie PHP przechodzi: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Przegląd schematu potwierdza jeden cel na źródło/provider/konto, brak sekretów
  i możliwość późniejszego użycia relacji przez S-09.

**Uwaga implementacyjna**: Po zielonych testach automatycznych zatrzymaj się na
zatwierdzenie ręcznego przeglądu modelu przed rozpoczęciem Fazy 2.

---

## Phase 2: Adaptery Spotify i YouTube

### Przegląd

Budujemy wąskie, fake'owalne klienty odczytu i zapisu playlist. Adaptery
odpowiadają za providerowe endpointy, widoczność, marker, bounded responses i
deterministyczną konwergencję do frozen manifestu.

### Wymagane zmiany

#### 1. Provider-neutralny port zapisu

**Pliki**:

- `app/Integrations/PlaylistExport/Contracts/PlaylistWriter.php`
- `app/Integrations/PlaylistExport/Contracts/ExportMutationGuard.php`
- `app/Integrations/PlaylistExport/Data/ExportPlaylistDefinition.php`
- `app/Integrations/PlaylistExport/Data/PlaylistWriteResult.php`
- `app/Integrations/PlaylistExport/PlaylistWriteFailure.php`
- `app/Integrations/PlaylistExport/ProviderPlaylistUrl.php`
- `app/Integrations/PlaylistExport/PlaylistWriterRegistry.php`

**Cel**: Oddzielić orkiestrację i dostęp do tokenu od protokołów Spotify oraz
YouTube.

**Kontrakt**: Port obsługuje inspekcję celu, bounded paginowany scan owned
playlists po dokładnym markerze, create i exact replacement. Każdy klient ma
`connectTimeout(5)`, `timeout(10)` oraz wspólny budżet provider I/O 360 sekund
na jedno wykonanie joba. Recovery pobiera najwyżej 20 stron po maksymalnie 50
rekordów; przekroczenie limitu stron, czasu albo niepełna paginacja zwraca
`recovery-scan-incomplete` i wykonuje zero mutacji. Wejście ma
provider, target ID, nazwę/opis z markerem, widoczność oraz uporządkowane
providerowe ID/URI do 20 pozycji. Każda mutacja wymaga bezpośrednio przed
requestem pozytywnego wyniku efemerycznego `ExportMutationGuard`; odmowa
zatrzymuje pozostały plan zmian. Wynik zawiera tylko zwalidowane providerowe ID,
revision, snapshot pozycji lub zamknięty failure code; linki i URL-e zwrócone w
payloadzie providera nie są utrwalane ani renderowane. `ProviderPlaylistUrl`
buduje z ID wyłącznie kanoniczne HTTPS URL-e
`https://open.spotify.com/playlist/{id}` albo
`https://www.youtube.com/playlist?list={id}`. Nieprawidłowe ID mapuje się na
`invalid-response` przed persist.

#### 2. Adapter Spotify

**Pliki**:

- `app/Integrations/PlaylistExport/Providers/SpotifyPlaylistWriter.php`
- `tests/Unit/Integrations/PlaylistExport/SpotifyPlaylistWriterTest.php`

**Cel**: Tworzyć i aktualizować niepublikowane playlisty Spotify przy użyciu
aktualnych endpointów Web API.

**Kontrakt**: Create używa `POST /me/playlists` z `public: false`,
`collaborative: false` i markerem w opisie; scan paginuje `GET /me/playlists`,
filtruje ownera i exact marker; replacement używa
`PUT /playlists/{id}/items` dla maksymalnie 20 URI oraz zachowuje marker przez
aktualizację metadanych. Nie używa usuniętych ścieżek `/tracks`, nie wykonuje
ukrytego retry mutacji i nie interpretuje `public: false` jako pełnej kontroli
dostępu poza gwarancją braku profilu/search.

#### 3. Adapter YouTube

**Pliki**:

- `app/Integrations/PlaylistExport/Providers/YouTubePlaylistWriter.php`
- `tests/Unit/Integrations/PlaylistExport/YouTubePlaylistWriterTest.php`

**Cel**: Tworzyć playlisty `unlisted` i możliwie małym kosztem doprowadzać ich
pozycje do dokładnego manifestu.

**Kontrakt**: Create używa `playlists.insert`, scan paginuje `playlists.list`
z `mine=true`, a update zachowuje marker i `unlisted`. Adapter pobiera wszystkie
occurrence ID przez `playlistItems.list`, wylicza minimalne usunięcia,
przesunięcia i inserty, wykonuje je sekwencyjnie oraz weryfikuje końcową
kolejność. Powtórzenia pozostają odrębnymi occurrences; jeżeli zweryfikowany
kontrakt konta testowego ich nie obsługuje, manifest z duplikatem zwraca
`unsupported-duplicate` przed pierwszą mutacją.

#### 4. Recovery niejednoznacznego create

**Pliki**:

- `app/Integrations/PlaylistExport/Data/CreateRecoveryResult.php`
- testy obu adapterów z tej fazy

**Cel**: Odzyskać providerowe ID po utracie odpowiedzi bez automatycznego
tworzenia kolejnej kopii.

**Kontrakt**: Marker jest krótkim, stabilnym sufiksem opisu wyprowadzonym z
`operation_id`. Po niejednoznacznym create następna próba wykonuje pełny,
udany scan: jeden exact hit zostaje przyjęty, zero lub wiele wyników daje
`ambiguous-create` i nigdy nie wywołuje kolejnego create. Retry tego stanu tylko
powtarza scan. Przekroczenie 20 stron, 1000 rekordów albo 360-sekundowego budżetu
nie jest interpretowane jako zero wyników: daje `recovery-scan-incomplete`,
zachowuje operację w recovery i wykonuje zero mutacji. Marker musi być zachowany
przez każdą aktualizację metadanych.

#### 5. Rejestracja adapterów i kontrakt źródeł

**Pliki**:

- `app/Providers/PlaylistExportServiceProvider.php`
- `bootstrap/providers.php`
- `scripts/verify-source-contract`

**Cel**: Rejestrować klientów jawnie i utrzymać repozytoryjny allowlist nowych
stabilnych ścieżek.

**Kontrakt**: Registry rozwiązuje wyłącznie dwa obsługiwane providery. Wszystkie
nowe pliki PHP pod `app/` i `tests/` oraz świadomie dodane stabilne migracje i
widoki zostają wpisane do source contract; nie dodajemy sekretów ani runtime
artefacts.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy Spotify pokrywają exact request/response, `public: false`, marker,
  paginację, create, update, 0/1/20 pozycji oraz 401/403/404/429/5xx/timeout:
  `php artisan test tests/Unit/Integrations/PlaylistExport/SpotifyPlaylistWriterTest.php`.
- Testy YouTube pokrywają `unlisted`, minimalny diff, kolejność, duplikaty,
  paginację, kosztowne mutacje i błędy providerowe:
  `php artisan test tests/Unit/Integrations/PlaylistExport/YouTubePlaylistWriterTest.php`.
- Testy recovery dowodzą: jeden marker odzyskuje ID, zero/wiele/niepełny scan
  wykonuje zero kolejnych create.
- Testy kanonizacji odrzucają nieprawidłowe i nadmiarowe ID, znaki kontrolne,
  userinfo, port, fragment, obcy host i schemat `javascript:`. Hostile URL z
  payloadu providera nie może wpłynąć na link zbudowany lokalnie z ID.
- Test worst-case przechodzi dla 20 pełnych stron/1000 playlist, a strona 21,
  przekroczenie 360 sekund i niepełna paginacja kończą się
  `recovery-scan-incomplete` bez mutacji. Test konfiguracji dowodzi relacji
  `450 s job timeout < 480 s overlap lease < 510 s queue retry_after`.
- Source contract i formatowanie przechodzą:
  `sh scripts/verify-source-contract --worktree && vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Na kontach testowych Spotify i YouTube marker jest zwracany przez pełny scan,
  pozostaje po aktualizacji metadanych i pozwala przyjąć playlistę po
  zasymulowanej utracie odpowiedzi.
- Smoke YouTube potwierdza albo odrzuca zachowanie duplikatów; w obu przypadkach
  rezultat odpowiada kontraktowi bez cichej deduplikacji.
- Pełny scan obu kont managed potwierdza przed Fazą 3, że każde mieści się w
  obsługiwanym limicie 1000 playlist; przekroczenie blokuje rozpoczęcie Fazy 3
  do czasu zmiany jawnego budżetu albo strategii recovery.
- Przed rozpoczęciem implementacji Phase 3 przegląd opublikowanego kontraktu
  PaaS potwierdza jego dokładną nazwę i wersję oraz wszystkie gwarancje managed
  export: technical write, worker availability, rotację przed mutacją,
  concurrency, zamknięte błędy i brak fallbacku. Brak którejkolwiek gwarancji
  blokuje ścieżkę managed, nie powoduje kopiowania mechaniki PaaS do aplikacji.

**Uwaga implementacyjna**: Po Fazie 2 obowiązuje formalny stop całej zmiany.
Faza 3 może rozpocząć się dopiero po udokumentowaniu wyniku obu testów markerów
i duplikatów YouTube oraz zaliczeniu bramki 2.10 przez wpisanie do planu
dokładnej nazwy i wersji opublikowanego kontraktu PaaS. Do tego czasu nie wolno
wdrażać części wspólnej orkiestracji ani osobnej ścieżki linked.

---

## Phase 3: Wspólna orkiestracja wykonania

### Przegląd

Łączymy trwałą operację, frozen manifest, dostęp linked/managed, adaptery,
admission i retry w jeden worker bez locków utrzymywanych podczas sieci.

### Wymagane zmiany

#### 1. Bezpieczne granice dostępu linked i managed

**Pliki**:

- `app/Integrations/PlaylistExport/Contracts/WithExportAccess.php`
- `app/Integrations/PlaylistExport/Contracts/ManagedExportCredentialSource.php`
- `app/Integrations/PlaylistExport/Contracts/PublishManagedRefreshToken.php`
- `app/Integrations/PlaylistExport/Data/ManagedExportCredential.php`
- `app/Integrations/PlaylistExport/Actions/WithLinkedExportAccess.php`
- `app/Integrations/PlaylistExport/Actions/WithManagedExportAccess.php`
- `app/Integrations/PlaylistExport/Actions/LinkedExportMutationGuard.php`
- `app/Integrations/PlaylistExport/Actions/AllowManagedExportMutation.php`
- `config/services.php`
- `.env.example`
- testy jednostkowe obu wariantów dostępu

**Cel**: Dostarczyć efemeryczny token do adaptera bez zapisania go w operacji,
jobie, wyniku lub logach.

**Kontrakt**: Linked lookup używa ownera, providera i zapisanego
`target_account_id`, po czym deleguje do `WithStreamingAccess`; ponowne
połączenie jest dozwolone wyłącznie dla tej samej providerowej tożsamości.
`WithLinkedExportAccess` snapshotuje `streaming_account_id`, providerową
tożsamość i `credential_version` wewnątrz synchronicznego callbacku
`WithStreamingAccess`, po utrwaleniu ewentualnego replacement refresh tokenu i
zwiększeniu jego wersji, lecz przed wejściem do adaptera. Przekazany writerowi
guard bezpośrednio przed każdą mutacją potwierdza tego samego ownera, provider,
`target_account_id`, niezmienioną wersję poświadczenia oraz obecność refresh
tokenu. Nie wolno snapshotować wersji sprzed wywołania `WithStreamingAccess`,
ponieważ poprawna rotacja uczyniłaby ją nieaktualną. Odmowa przed pierwszą
mutacją kończy preflight bez zapisu; odmowa po wcześniejszej mutacji zatrzymuje
kolejne requesty i klasyfikuje operację jako `incomplete`. Managed
używa osobnych aplikacyjnych portów i DTO opartych na opublikowanej,
wersjonowanej publicznej capability PaaS dla managed export; nie reużywa
probe-only `TechnicalConfiguration`, `RefreshTokenRotationSink` ani typów
`ProbeFailure`. Przed implementacją tej ścieżki plan zostaje uzupełniony o
dokładną nazwę i wersję opublikowanego kontraktu. Kontrakt musi gwarantować
techniczne `write`, dostępność konfiguracji dla zwykłego queue workera,
właściwą providerową tożsamość i zakresy, publikację replacement refresh tokenu
przed pierwszą mutacją, bezpieczną konkurencję rotacji, zamknięte błędy oraz brak
fallbacku do tester albo user credentials. Failure publikacji kończy preflight
bez mutacji. `README.md` dokumentuje przyjęty kontrakt konsumencki dopiero po
jego publikacji. Adapter nie wywołuje probe i nie opisuje implementacji
Managera.

#### 2. Worker i orkiestrator

**Pliki**:

- `app/Actions/Exports/RunExportOperation.php`
- `app/Actions/Exports/RecoverStaleExportOperation.php`
- `app/Jobs/ExecuteExportOperation.php`
- `app/Console/Commands/ReconcileStaleExportOperations.php`
- `routes/console.php`
- `tests/Feature/Exports/ExecuteExportOperationTest.php`

**Cel**: Wykonać idempotentną operację oraz konwergować ten sam cel po awarii.

**Kontrakt**: Job używa `WithoutOverlapping` po operation ID, trzech prób,
backoff `[60, 300]`, timeoutu zgodnego z workerem i lease dłuższego od timeoutu.
Terminalny replay jest no-op. Krótki atomic claim zwiększa attempt count, ale
żadna transakcja ani row lock nie obejmuje OAuth/API. Worker ładuje wyłącznie
confirmed review i jego pozycje, nigdy aktualną zawartość banku jako wejście.
Idempotentny `failed(Throwable)` ponownie ładuje operację i pod krótkim lockiem
klasyfikuje nieterminalny stan według `provider_mutation_started_at`: przed
pierwszą mutacją jako `failed`, po niej jako `incomplete`. Ponieważ hook nie
uruchomi się po każdym twardym przerwaniu procesu, uruchamiany co minutę
reconciler obejmuje również `processing`, którego `updated_at` jest starsze niż
aktywny `retry_after` kolejki. Reconciler stosuje tę samą klasyfikację pod
lockiem i warunkową aktualizację, nie nadpisuje późnego sukcesu oraz dispatchuje
ten sam operation ID wyłącznie dla retryable failure z niewykorzystaną próbą;
po wyczerpaniu prób pozostawia ręczne retry. Próg nie może być krótszy od
timeoutu joba, overlap lease ani widoczności wiadomości w używanym połączeniu.

#### 3. Dopuszczenie YouTube

**Pliki**:

- `app/Actions/Exports/RunExportOperation.php`
- `tests/Feature/Exports/YouTubeExportAdmissionTest.php`

**Cel**: Włączyć oba typy eksportu do wspólnego limitu F-02 bez częściowej
mutacji przed odmową.

**Kontrakt**: Po utrwaleniu operacji i poza transakcją wywołujemy
`AdmitYouTubeWrite` z `LinkedExport` albo `ManagedExport` oraz tym samym UUID na
każdej próbie, bezpośrednio przed pierwszą mutacją. `admitted-new` i
`admitted-existing` zezwalają; `limit-reached` lub wyjątek wykonuje zero
providerowych mutacji. Rezerwacja nie jest refundowana po awarii.

#### 4. Checkpointy, relacja i exact replacement

**Pliki**:

- `app/Actions/Exports/PersistExportTarget.php`
- `app/Models/Playlist.php`
- `app/Models/PlaylistExportLink.php`
- `app/Models/ExportOperation.php`

**Cel**: Zapisać cel natychmiast po create/recovery i aktualizować go tylko po
providerowym ID.

**Kontrakt**: Potwierdzony create lub pojedynczy recovery hit atomowo tworzy
target `Playlist` i link. `canonical_source_url` targetu jest budowany lokalnie
przez `ProviderPlaylistUrl` wyłącznie ze zwalidowanego providerowego ID, nigdy z
URL-a odpowiedzi API. Następne próby wywołują inspect/replace wyłącznie dla
`target_playlist.source_playlist_id`. Sukces odtwarza lokalny snapshot target
items i revision, ustawia `transferred` i czyści active key operacji. 404
zapisanego celu atomowo oznacza link jako retired, czyści oba active keys, daje
terminalne `target-deleted` i pozwala dopiero nowemu review utworzyć następcę.

#### 5. Klasyfikacja awarii i retry

**Pliki**:

- `app/Actions/Exports/ClassifyExportFailure.php`
- `app/Actions/Exports/RetryExportOperation.php`
- `app/Enums/ExportOperationFailure.php`

**Cel**: Rozróżnić bezpieczne retry, częściową mutację i stan terminalny bez
ujawniania payloadów providera.

**Kontrakt**: Rate limit, quota, chwilowa niedostępność i awarie transportu są
automatycznie ponawiane do limitu, następnie udostępniają ręczne retry.
Reconnect/missing scope wskazuje działanie użytkownika. Awaria po
`provider_mutation_started_at` daje `incomplete`; wcześniejsza `failed`.
`ambiguous-create` retry wykonuje tylko recovery scan. Osobna owner-scoped akcja
porzucenia jest dozwolona wyłącznie dla tego failure code i wymaga jawnego
potwierdzenia sprawdzenia/usunięcia orphan copies; ustawia terminalne
`recovery-abandoned` i czyści active key. Inne konto, usunięty cel i
nieobsługiwany duplikat wymagają nowego review lub korekty źródła.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Feature tests przechodzą dla create/update na linked i managed Spotify oraz
  YouTube, z zachowaniem tej samej relacji i providerowego ID. Przypadek linked
  z replacement refresh tokenem dowodzi, że snapshot używa wersji po rotacji i
  pierwsza mutacja nie jest fałszywie odrzucona:
  `php artisan test tests/Feature/Exports/ExecuteExportOperationTest.php`.
- Consumer tests dowodzą, że odmowa/awaria admission powoduje zero mutacji,
  retry używa tej samej rezerwacji, a Spotify nie wywołuje admission:
  `php artisan test tests/Feature/Exports/YouTubeExportAdmissionTest.php`.
- Testy obejmują unlink/relink tej samej tożsamości, inne konto, częściowy zapis,
  crash przed i po utrwaleniu ID, target 404, duplikaty i terminalny replay.
- Test współbieżnego unlinku usuwa konto między dwiema mutacjami i dowodzi, że
  guard blokuje wszystkie kolejne requesty oraz pozostawia operację jako
  bezpiecznie ponawialne `incomplete`.
- Test timeoutu i osieroconego `processing` dowodzi klasyfikacji `failed` przed
  checkpointem i `incomplete` po nim, ponownego dispatchu tej samej operacji
  przy dostępnej próbie oraz braku nadpisania późnego sukcesu przez reconciler.
- Formatowanie PHP przechodzi: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Fault walkthrough potwierdza właściwy stan dla przerwania przed mutacją, po
  create, podczas replacement i po zewnętrznym sukcesie przed lokalnym commit.
- Przegląd logów, job payloadów i rekordów potwierdza brak tokenów, refresh
  tokenów i surowych odpowiedzi providerów.

**Uwaga implementacyjna**: Po zielonej orkiestracji zatrzymaj się przed UI, aby
potwierdzić mapowanie wszystkich punktów przerwania na statusy FR-011.

---

## Phase 4: Status, wynik i powiadomienia

### Przegląd

Dodajemy owner-scoped ekran operacji, polling, retry/recovery, widok relacji w
banku i niezawodne powiadomienie dla operacji trwających co najmniej minutę.

### Wymagane zmiany

#### 1. Trasy, kontroler i retry request

**Pliki**:

- `routes/web.php`
- `app/Http/Controllers/ExportOperationController.php`
- `app/Http/Requests/RetryExportOperationRequest.php`
- `app/Http/Requests/AbandonExportRecoveryRequest.php`
- `app/Actions/Exports/AbandonExportRecovery.php`
- `app/Http/Controllers/PlaylistExportReviewController.php`

**Cel**: Udostępnić jeden kanoniczny wynik po potwierdzeniu HTTP i Livewire.

**Kontrakt**: `GET /bank/playlists/{playlist}/exports/{exportOperation}` oraz
throttled `POST .../retry` i `POST .../abandon-recovery` używają łańcucha user →
source playlist → operation i zwracają 404 dla obcego zasobu. Retry działa tylko
dla dozwolonych stanów tej samej operacji, nie tworzy nowego operation ID i przy
`ambiguous-create` uruchamia wyłącznie scan. Porzucenie wymaga dedykowanego pola
potwierdzenia, działa tylko dla niejednoznacznego create, zwalnia active key i
nie mutuje providera. Confirm przekierowuje do nowego wyniku.

#### 2. Panel stanu operacji

**Pliki**:

- `app/Livewire/ExportOperationPanel.php`
- `resources/views/exports/show.blade.php`
- `resources/views/livewire/export-operation-panel.blade.php`
- `app/Livewire/ExportReviewPanel.php`
- `resources/views/livewire/export-review-panel.blade.php`

**Cel**: Pokazać spójny cykl użytkownikowi i pozwolić opuścić ekran bez
anulowania pracy.

**Kontrakt**: Panel polluje tylko `queued|processing`; mapuje stany na dokładne
copy FR-011. Sukces pokazuje link oraz „na Twoim koncie” lub „zarządzana przez
music-map” wraz z instrukcją. Link pochodzi wyłącznie z lokalnie zbudowanego
kanonicznego HTTPS URL-a targetu i jest renderowany z `rel="noreferrer noopener"`.
Retryable failure pokazuje przyczynę i jeden
przycisk. Target deleted kieruje do nowego review. Ambiguous create instruuje,
  jak sprawdzić/usunąć niejednoznaczne kopie u providera, retry tylko skanuje, a
  jawne porzucenie po wykonaniu instrukcji umożliwia nowy review. Focus, role
  status/alert i obsługa klawiatury pozostają dostępne.

#### 3. Relacje i status na karcie banku

**Pliki**:

- `app/Http/Controllers/BankController.php`
- `resources/views/bank/index.blade.php`

**Cel**: Prezentować docelowe kopie pod źródłem zamiast jako równorzędne karty i
nie proponować nowego review podczas aktywnego eksportu.

**Kontrakt**: Query ładuje źródłowe playlisty, linki i najnowsze operacje bez
N+1. Każda powiązana kopia pokazuje provider, właściciela, status i link. Aktywna
operacja prowadzi do panelu. Świeży review aktualizuje zapisane ID aktywnego
linku; po `target-deleted` wycofany link pozostaje historią, a świadomie
potwierdzony review tworzy następcę z nowym providerowym ID.

#### 4. Powiadomienie po długiej operacji

**Pliki**:

- `app/Jobs/SendExportOperationCompletedNotification.php`
- `app/Notifications/ExportOperationCompleted.php`
- `resources/views/mail/export-operation-completed.blade.php`
- testy powiadomień eksportu

**Cel**: Zapewnić co najmniej jednokrotną dostawę, gdy użytkownik nie powinien
aktywnie czekać, z best-effort tłumieniem duplikatów.

**Kontrakt**: Terminalna operacja z czasem co najmniej 60 sekund dispatchuje
job chroniony `WithoutOverlapping` po operation ID. Marker
`notification_sent_at` jest ustawiany dopiero po udanym `notifyNow`; wyjątek
pozostawia go pustym, aby retry nie zgubił wymaganej wiadomości. Gwarancja jest
at-least-once: marker tłumi zwykłe ponowienia, lecz crash po przyjęciu wiadomości
przez transport, a przed zapisem markera, może spowodować duplikat. Wiadomość nie
zawiera sekretów i prowadzi do owner-scoped wyniku.

#### 5. Testy HTTP, Livewire i prezentacji

**Pliki**:

- `tests/Feature/Exports/ExportOperationRouteTest.php`
- `tests/Feature/Exports/ExportOperationPanelTest.php`
- `tests/Feature/Exports/ExportOperationNotificationTest.php`
- `tests/Feature/BankAccessTest.php`

**Cel**: Zweryfikować uprawnienia, stany, retry i oba warianty właściciela bez
testów zależnych od struktury DOM.

**Kontrakt**: Testy używają dostępnościowych etykiet i tekstu, pokrywają auth,
verified, CSRF, cross-user 404, throttle, polling terminalny, double click,
  odświeżenie strony, retry i porzucenie recovery, pięć statusów, owner copy,
  link oraz próg 60 sekund.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy tras/panelu/banku przechodzą:
  `php artisan test tests/Feature/Exports/ExportOperationRouteTest.php tests/Feature/Exports/ExportOperationPanelTest.php tests/Feature/BankAccessTest.php`.
- Testy powiadomień dowodzą progu 60 sekund, at-least-once delivery, tłumienia
  równoległych i zwykłych ponowień oraz retry po błędzie delivery. Osobny test
  dokumentuje dopuszczony duplikat w crash window po delivery, ale przed
  zapisem markera:
  `php artisan test tests/Feature/Exports/ExportOperationNotificationTest.php`.
- Produkcyjny frontend buduje się: `npm run build`.
- Formatowanie PHP przechodzi: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Człowiek sprawdza mobile, desktop, powiększenie 200%, klawiaturę i czytnik
  ekranu dla processing, success, failed, incomplete i recovery.
- Użytkownik opuszcza ekran operacji trwającej ponad minutę, otrzymuje
  powiadomienie i wraca do właściwego wyniku; zwykłe retry nie wysyła duplikatu.

**Uwaga implementacyjna**: Po ukończeniu UI zatrzymaj się przed końcowym smoke,
aby potwierdzić copy, dostępność i instrukcje właściciela.

---

## Phase 5: Współbieżność i akceptacja integracyjna

### Przegląd

Domykamy ryzyka właściwe produkcyjnemu PostgreSQL, pełne bramki repozytorium i
rzeczywiste ścieżki obu providerów oraz obu modeli własności.

### Wymagane zmiany

#### 1. Testy współbieżności PostgreSQL

**Plik**: `tests/Feature/Exports/ExportOperationPostgresTest.php`

**Cel**: Udowodnić działanie unikalności i lock order tam, gdzie SQLite nie
odtwarza zachowania produkcji.

**Kontrakt**: Testy wymagają PostgreSQL i `pcntl`; pomijają się jawnie bez tych
warunków. Pokrywają równoległe potwierdzenia jednego review, dwa świeże review
tej samej relacji, nakładające się workery, unlink/relink, jeden trwały target,
jedną operację per review i brak deadlocków.

#### 2. Integracja konsumenta admission

**Plik**: `tests/Feature/Exports/YouTubeExportAdmissionPostgresTest.php`

**Cel**: Zweryfikować F-02 na granicy rzeczywistego konsumenta, nie tylko portu.

**Kontrakt**: Równoległe retry tej samej operacji używa jednego ledger entry;
odmowa wykonuje zero write HTTP; utrata potwierdzenia commitu admission kończy
się `admitted-existing` przed pierwszą mutacją. Test nie kopiuje implementacji
F-02.

#### 3. Pełne quality gates i dokumentacja konfiguracji

**Pliki**:

- `scripts/verify-source-contract`
- `.env.example`
- `README.md`

**Cel**: Utrzymać agent-readiness, bezpieczne placeholdery i publiczny kontrakt
uruchomieniowy eksportu.

**Kontrakt**: Dokumentacja wymienia jedynie consumer-visible nazwy konfiguracji,
statusy oraz semantykę technicznego refresh/rotation używaną przez managed
export: właściwa providerowa tożsamość i zakresy, replacement opublikowany przed
mutacją, failure rotacji oznaczający zero zapisów. Nie zawiera sekretów ani
szczegółów transportu, montowania, locków czy lifecycle Managera.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy PostgreSQL przechodzą na disposable database:
  `php artisan test tests/Feature/Exports/ExportOperationPostgresTest.php tests/Feature/Exports/YouTubeExportAdmissionPostgresTest.php`.
- Pełny suite przechodzi: `composer test`.
- PHP style i frontend build przechodzą:
  `vendor/bin/pint --test && npm run build`.
- Source contract przechodzi dla worktree i tracked candidate:
  `sh scripts/verify-source-contract --worktree` oraz
  `sh scripts/verify-source-contract --tracked` po dodaniu plików do indeksu.

#### Weryfikacja ręczna

- Linked i managed Spotify przechodzą create, update tego samego ID, recovery
  markera, exact order, `public: false` i stabilny link; zero/wiele markerów nie
  wykonuje kolejnego create.
- Linked i managed YouTube przechodzą create, update tego samego ID, recovery,
  `unlisted`, exact order i zachowanie duplikatów albo odmowę przed mutacją;
  wyczerpane logical admission wykonuje zero zapisów.
- Częściowy zapis, retry, target deletion, odłączenie/relink tej samej
  tożsamości, odrzucenie innej tożsamości oraz powiadomienie po 60 sekundach
  zachowują kontrakt.
- Przed wdrożeniem wykonano nadzorowaną addytywną publikację schematu, a dowody
  smoke nie zawierają tokenów, providerowych payloadów ani identyfikatorów kont.

**Uwaga implementacyjna**: Zmiana jest gotowa do archiwizacji dopiero po
zatwierdzeniu czterech rzeczywistych ścieżek provider × ownership oraz dowodu
zielonego PostgreSQL.

---

## Strategia testowania

### Testy jednostkowe

- Adaptery HTTP: dokładne metody, aktualne endpointy, nagłówki, payloady,
  widoczność, paginacja, bounded responses i zamknięte błędy.
- Spotify: create, metadata, exact replace 0/1/20, marker scan oraz brak użycia
  usuniętych `/tracks` endpoints.
- YouTube: create/list/update, minimalna rekoncyliacja occurrence IDs,
  kolejność, duplikaty, quota/rate errors i końcowa weryfikacja.
- Dostęp linked/managed: tylko efemeryczny token, poprawna tożsamość i rotacja
  przez publiczny kontrakt.

### Testy integracyjne

- Confirm → durable operation → after-commit dispatch → worker → target/link.
- Create i kolejne update tego samego providerowego ID dla czterech ścieżek.
- Admission deny/unavailable/existing, częściowa mutacja i ten sam klucz retry.
- Races: double confirm, dwa review jednego celu, overlapping workers oraz
  disconnect/relink.
- Owner-scoped HTTP/Livewire, retry throttle, polling i at-least-once
  notification z best-effort deduplikacją.
- PostgreSQL constraints, lock order i utrata potwierdzenia commitu.

### Kroki testowania ręcznego

1. Na linked Spotify potwierdź review, sprawdź create, `public: false`, właściciela
   i link; zmień bank, wykonaj nowe review i potwierdź update tego samego ID.
2. Powtórz dla managed Spotify, sprawdzając instrukcję i właściciela `music-map`.
3. Powtórz oba tryby dla YouTube, sprawdzając `unlisted`, limit admission i
   providerową kwotę.
4. Dla obu providerów zasymuluj timeout create, wykonaj recovery scan i
   potwierdź brak drugiej kopii; dla zero/wielu markerów sprawdź retry samego
   skanu, instrukcję usunięcia orphan i jawne porzucenie przed nowym review.
5. Przerwij zapis elementów, ponów operację i potwierdź exact order oraz to samo
   ID; usuń cel u providera i potwierdź wymaganie nowego review.
6. Odłącz konto podczas operacji, połącz tę samą tożsamość i ponów; następnie
   połącz inną tożsamość i potwierdź odmowę.
7. Pozostaw operację na ponad 60 sekund, opuść ekran i potwierdź powiadomienie
   prowadzące do owner-scoped wyniku oraz brak duplikatu podczas zwykłego retry.
8. Sprawdź bank i panel na mobile/desktop, przy 200% zoom, klawiaturą i czytnikiem
   ekranu.

## Uwagi dotyczące wydajności

Zakres do 20 pozycji pozwala Spotify wykonać replacement jednym requestem.
YouTube wymaga wielu wywołań, dlatego implementacja oblicza minimalny diff,
kończy pętlę na pierwszym błędzie i zawsze weryfikuje rezultat. Pełny recovery
scan jest paginowany i uruchamiany tylko po niejednoznacznym create; nie należy
go wykonywać w normalnym update.

Job ma timeout 450 sekund, `WithoutOverlapping` expiry 480 sekund, queue
`retry_after` 510 sekund i backoff 60/300. Adaptery egzekwują wspólny budżet
provider I/O 360 sekund, aby pozostawić czas na checkpointy i bezpieczne
zakończenie przed timeoutem joba. Automatyczny test chroni relację
`job timeout < overlap lease < queue retry_after`; zmiana którejkolwiek wartości
wymaga jednoczesnej aktualizacji pozostałych oraz testu worst-case.

## Uwagi dotyczące migracji

Migracje są addytywne. Istniejące rekordy otrzymują rolę `source` i pozostają
bez relacji eksportu. Istniejące `confirmed` review bez `ExportOperation` są
historyczne i nie są automatycznie ani przez replay przekształcane w eksport;
użytkownik musi utworzyć oraz świadomie zatwierdzić świeży review. Publikacja
jest dwustopniowa: najpierw wdrażamy migrację oraz kompatybilny kod rozumiejący
i filtrujący `export_target`, a dopiero potem włączamy tworzenie docelowych
rekordów przez worker.

Po utworzeniu pierwszego `export_target` rollback kodu może wrócić wyłącznie do
wersji kompatybilnej z rolą; starsza wersja pokazałaby targety jako źródła.
Migracje `down()` usuwają nowe tabele i kolumnę roli wyłącznie przed włączeniem
tworzenia targetów albo po kontrolowanym usunięciu ich danych. Produkcja nie
wykonuje automatycznego destrukcyjnego rollbacku danych eksportu.

## Referencje

- PRD: `context/foundation/prd.md`, szczególnie US-01, FR-009–FR-011,
  NFR-002–NFR-003 i NFR-006.
- Roadmapa: `context/foundation/roadmap.md`, S-06 i S-07.
- Handoff frozen manifestu:
  `context/archive/2026-09-14-export-match-review/plan.md`.
- Dostęp linked:
  `context/archive/2026-09-13-streaming-account-linking/plan.md`.
- Admission YouTube:
  `context/archive/2026-09-14-youtube-write-admission/plan.md`.
- Aktualny start review: `app/Actions/ExportReviews/StartExportReview.php:20`.
- Aktualne potwierdzenie: `app/Actions/ExportReviews/ConfirmExportReview.php:26`.
- Frozen DTO:
  `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:13`.
- Dostęp efemeryczny:
  `app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php:27`.
- Admission port:
  `app/Integrations/YouTubeWriteAdmission/Contracts/AdmitYouTubeWrite.php:9`.
- Oficjalne Spotify Create Playlist:
  https://developer.spotify.com/documentation/web-api/reference/create-playlist
- Oficjalne Spotify Update Playlist Items:
  https://developer.spotify.com/documentation/web-api/reference/reorder-or-replace-playlists-items
- Oficjalne Spotify Current User's Playlists:
  https://developer.spotify.com/documentation/web-api/reference/get-a-list-of-current-users-playlists
- Oficjalne YouTube playlists:
  https://developers.google.com/youtube/v3/docs/playlists
- Oficjalne YouTube playlistItems:
  https://developers.google.com/youtube/v3/docs/playlistItems

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Trwały model eksportu i atomowy start

#### Automated

- [x] 1.1 Migracje i kontrakty modeli przechodzą na SQLite — 49599fe
- [x] 1.2 Start operacji jest idempotentny i dispatchuje wyłącznie po commit — 49599fe
- [x] 1.3 Formatowanie PHP przechodzi — 49599fe
- [x] 1.5 Rola playlisty izoluje cele eksportu od lifecycle źródeł — 49599fe
- [x] 1.6 Reconciler odzyskuje operację po crashu między commit a enqueue — 49599fe

#### Manual

- [ ] 1.4 Schemat zachowuje własność, brak sekretów i gotowość relacji dla S-09

### Phase 2: Adaptery Spotify i YouTube

#### Automated

- [x] 2.1 Kontrakt adaptera Spotify przechodzi dla create, replace, scan i awarii — 220b4b5
- [x] 2.2 Kontrakt adaptera YouTube przechodzi dla minimalnego diffu, kolejności i awarii — 220b4b5
- [x] 2.3 Recovery marker nigdy nie powtarza create po niejednoznacznym wyniku — 220b4b5
- [x] 2.4 Source contract i formatowanie przechodzą — 220b4b5
- [x] 2.7 Recovery respektuje limit 20 stron i budżet czasu bez mutacji po przekroczeniu — 220b4b5
- [x] 2.9 Kanoniczny link powstaje lokalnie ze zwalidowanego providerowego ID — 220b4b5

#### Manual

- [ ] 2.5 Markery obu providerów działają na rzeczywistych kontach testowych
- [ ] 2.6 Zachowanie duplikatów YouTube jest udokumentowane i zgodne z fail-closed
- [ ] 2.8 Konta managed mieszczą się w limicie 1000 playlist przed rozpoczęciem Fazy 3
- [ ] 2.10 Opublikowany kontrakt PaaS gwarantuje managed write i rotację dla zwykłego workera

### Phase 3: Wspólna orkiestracja wykonania

#### Automated

- [x] 3.1 Linked i managed create/update używają jednej trwałej relacji i tego samego providerowego ID — 1442d9a
- [x] 3.2 Admission YouTube poprzedza każdą pierwszą mutację i odmawia bez zapisu — 1442d9a
- [x] 3.3 Retry, checkpointy, relink i częściowe awarie konwergują bez nowego celu — 1442d9a
- [x] 3.4 Formatowanie PHP przechodzi — 1442d9a
- [x] 3.7 Unlink między mutacjami zatrzymuje dalsze zapisy przez efemeryczny guard — 1442d9a
- [x] 3.8 Timeout i osierocone processing są odzyskiwane bez nadpisania późnego sukcesu — 1442d9a

#### Manual

- [ ] 3.5 Punkty przerwania mapują się na właściwe statusy FR-011
- [ ] 3.6 Operacje, joby i logi nie zawierają sekretów ani surowych payloadów

### Phase 4: Status, wynik i powiadomienia

#### Automated

- [x] 4.1 Trasy, panel i bank zachowują owner scope, retry, recovery i pięć statusów — 97fda2e
- [x] 4.2 Powiadomienie po 60 sekundach ma at-least-once delivery i best-effort deduplikację — 97fda2e
- [x] 4.3 Produkcyjny frontend buduje się — 97fda2e
- [x] 4.4 Formatowanie PHP przechodzi — 97fda2e

#### Manual

- [ ] 4.5 Panel i bank przechodzą przegląd dostępności oraz responsywności
- [ ] 4.6 Długa operacja wysyła jedno powiadomienie do właściwego wyniku

### Phase 5: Współbieżność i akceptacja integracyjna

#### Automated

- [x] 5.1 Testy operacji i consumer admission przechodzą na PostgreSQL
- [x] 5.2 Pełny PHPUnit suite przechodzi
- [x] 5.3 Pint i produkcyjny frontend build przechodzą
- [x] 5.4 Source contract przechodzi dla worktree i tracked candidate

#### Manual

- [ ] 5.5 Linked i managed Spotify przechodzą create, update, recovery i kontrolę widoczności
- [ ] 5.6 Linked i managed YouTube przechodzą create, update, recovery i kontrolę widoczności
- [ ] 5.7 Partial retry, relink, target deletion, quota i powiadomienie spełniają kontrakt
- [ ] 5.8 Addytywna publikacja schematu i dowody smoke nie ujawniają sekretów
