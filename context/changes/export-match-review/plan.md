# Kontrola dopasowania przed eksportem — plan implementacji

## Przegląd

S-05 dodaje bezpieczny krok pomiędzy prywatnym bankiem a późniejszym eksportem:
użytkownik wybiera dozwolony cel, system asynchronicznie wyszukuje odpowiedniki
do 20 pozycji w katalogu Spotify albo YouTube, a następnie pokazuje osobno
dopasowania pewne, podejrzane i niedostępne. Użytkownik rozstrzyga każdą
podejrzaną pozycję, może pozostawić lub usunąć problematyczne pozycje z banku i
jednym świadomym potwierdzeniem zamraża dokładny manifest dla S-06 albo S-07.

Sam S-05 nie zapisuje playlist ani utworów na platformach streamingowych.

## Analiza stanu obecnego

`main` zawiera gotowe konto aplikacji, prywatny pusty bank, powiązania Spotify i
YouTube oraz bezpieczny port krótkotrwałego dostępu użytkownika. Prywatne trasy
działają pod `auth` i `verified` (`routes/web.php:21`), a rekordy integracji są
wyszukiwane przez relację właściciela, dzięki czemu obce ID kończy się 404
(`app/Http/Controllers/StreamingAccountController.php:22`).

Bezpośrednie wymaganie wstępne S-02 nie znajduje się jeszcze na `main`, ale jego
finalna gałąź `feat/playlist-link-import` ma status `implemented`. Dostarcza
`Playlist`, uporządkowane `PlaylistItem`, prywatną relację
`User::playlists()`, limit 20 pozycji i atomowy reimport. Reimport usuwa i
odtwarza wiersze pozycji, dlatego ich klucze główne nie są stabilne między
przygotowaniem a potwierdzeniem (`app/Actions/Playlists/ReplaceImportedPlaylist.php:44`
na gałęzi S-02). To wymusza samowystarczalny snapshot pozycji i deterministyczny
hash zawartości w przeglądzie.

Warstwa runtime ma bazodanową kolejkę (`config/queue.php:16`) i osobny worker z
90-sekundowym timeoutem (`Dockerfile:115`). Testy używają kolejki synchronicznej
(`phpunit.xml:20`), więc zachowanie joba musi być sprawdzalne zarówno przez
`Queue::fake()`, jak i bezpośrednie wykonanie. Livewire 4 i Flux 2 są już
zależnościami (`composer.json:8`), natomiast własny JavaScript praktycznie nie
istnieje; okresowe odpytywanie należy więc zrealizować komponentem Livewire.

Istniejące `PlatformProbe` służą akceptacji dostępu, a
`StreamingOAuthGateway` obsługuje OAuth, identity i revoke. Żaden z nich nie jest
produkcyjnym portem wyszukiwania katalogowego. `WithStreamingAccess` dodatkowo
zamyka access token w synchronicznym callbacku i zwraca wyłącznie typowany wynik
(`app/Integrations/StreamingAccounts/Contracts/WithStreamingAccess.php:12`), co
należy zachować.

Aktualny limit YouTube `search.list` wynosi 100 wywołań dziennie w osobnym
koszyku, po 1 jednostce na wywołanie. Pełny niebuforowany przegląd 20 pozycji może
więc zużyć 20% dziennego limitu. Jest to osobny budżet od limitu zapisów z
NFR-006 i musi być chroniony przed rozpoczęciem częściowej próby.

## Pożądany stan końcowy

Właściciel playlisty może rozpocząć jeden idempotentny przegląd dla konkretnej
pary: playlista, jej uporządkowana zawartość, provider docelowy i jawnie pokazany
właściciel celu. Jeżeli powiązane konto celu istnieje i jest aktywne, celem jest
to konto; tryb zarządzany jest dostępny tylko wtedy, gdy konto danego providera
nie jest powiązane. Backend zawsze odrzuca tę samą parę provider–konto, z której
pochodzi źródło.

Po zakończeniu użytkownik widzi dokładne dane kandydata oraz jedną z klas:
`matched`, `suspicious`, `unavailable`. Pewne dopasowania pozostają domyślnie w
manifeście, każda podejrzana pozycja wymaga jawnego `keep` albo `remove`, a
niedostępna pozycja nigdy nie trafia do eksportu. Potwierdzenie jest
jednorazowe, blokuje pusty manifest, atomowo stosuje wybrane usunięcia z banku i
zamraża kolejność oraz dokładne docelowe ID. Zmiana zawartości albo wygaśnięcie
po 24 godzinach unieważnia wynik.

Operacja trwająca ponad 30 sekund pokazuje neutralny komunikat o dalszym
przetwarzaniu; po 60 sekundach ekran pozwala bezpiecznie odejść. Po terminalnym
wyniku takiej długiej operacji użytkownik dostaje dokładnie jeden e-mail oraz
widzi trwały status po powrocie do banku.

### Kluczowe odkrycia

- S-02 przechowuje kolejność i duplikaty, ale reimport odtwarza pozycje; przegląd
  musi identyfikować wystąpienie przez snapshot i pozycję, nie przez trwałość
  `playlist_item.id`.
- Spotify daje ISRC, artystów, album i czas trwania, natomiast import YouTube ma
  głównie video ID, tytuł i kanał; jeden próg dopasowania nie może udawać równej
  jakości danych wejściowych.
- Błąd transportu, 429, wyczerpanie kwoty albo nieprawidłowy payload jest
  wynikiem operacyjnym całej próby, nigdy statusem `unavailable` utworu.
- `StreamingAccount` nie przechowuje obecnie marketu Spotify; S-05 musi dodać
  nullable country/market, zapisywać go przy nowych połączeniach i rozwiązać go
  leniwie dla istniejących kont przed rozpoczęciem matchingu.
- Cache i dzienny budżet YouTube muszą deduplikować jednakowe fingerprinty oraz
  zarezerwować pełną liczbę brakujących wyszukiwań przed uruchomieniem joba.

## Czego NIE robimy

- Nie tworzymy ani nie aktualizujemy playlisty na Spotify lub YouTube.
- Nie realizujemy statusów, retry częściowego zapisu ani limitu zapisów z S-06,
  S-07 i FR-011/NFR-006.
- Nie pozwalamy wybierać ręcznego zamiennika ani przeglądać wielu kandydatów;
  to pozostaje poza MVP zgodnie z FR-013.
- Nie obsługujemy innych providerów ani playlist większych niż 20 pozycji.
- Nie zmieniamy zasad importu, synchronizacji ani edycji playlist z S-02/S-03.
- Nie używamy technical/tester probe jako gatewaya produktu i nie utrwalamy
  access tokenów ani surowych odpowiedzi providerów.
- Nie wykonujemy rzeczywistych wywołań Spotify lub YouTube w CI ani jako
  obowiązkowej ręcznej bramki ukończenia.
- Nie kopiujemy mechaniki dostarczania sekretów, wdrożeń ani hosta z Managera do
  kodu, planu lub testów aplikacji.

## Podejście do implementacji

Zmiana wprowadzi moduł `ExportMatching` z neutralnym portem katalogu,
providerowymi klientami i deterministycznym klasyfikatorem. Warstwa aplikacyjna
utworzy właścicielski `ExportReview`, skopiuje maksymalnie 20 uporządkowanych
pozycji i zapisze hash wejścia, a następnie wyśle `PrepareExportReview` na
kolejkę. Job użyje cache i budżetu, zapisze wynik wyłącznie po pomyślnym
przetworzeniu całego snapshotu, a operacyjne niepowodzenie zakończy próbę
typowanym kodem z bezpiecznym retry.

Ekran Livewire będzie odpytywał wyłącznie stany `queued` i `processing`.
Kontroler i akcje domenowe pozostaną granicą własności, idempotencji oraz
potwierdzenia. Potwierdzony rekord przeglądu wraz z jego pozycjami jest
niezmiennym handoffem: S-06/S-07 dostają identyfikator `ExportReview` i nie mogą
ponownie wyszukiwać ani zmieniać pokazanych docelowych ID.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Job nie może publikować częściowych pozycji. Przed publikacją ponownie sprawdza,
czy przegląd nadal jest aktywny, nie wygasł i zachował początkowy fingerprint;
spóźniony job po retry, ponownym uruchomieniu lub potwierdzeniu nie może nadpisać
nowszego stanu. Powiadomienie terminalne po przekroczeniu 60 sekund używa
atomowego znacznika wysłania, aby retry joba nie duplikowało e-maila.

### Sekwencjonowanie stanu

Potwierdzenie blokuje playlistę i przegląd w jednej transakcji, przelicza hash,
weryfikuje komplet decyzji, a następnie odtwarza uporządkowane pozycje banku bez
tych oznaczonych `remove`. Dopiero po udanym zapisie ustawia `confirmed_at` i
terminalny stan. Dzięki snapshotowi manifest zachowuje pokazane docelowe ID,
nawet jeśli odtworzenie banku nada pozycjom nowe klucze główne.

## Phase 1: Kontrakt przeglądu i wersjonowanie zawartości

### Przegląd

Ta faza ustanawia trwały, właścicielski model przeglądu oraz wszystkie
niezmienniki wymagane przez kolejkę, UI i późniejszy eksport.

### Wymagane zmiany

#### 1. Bazowy kontrakt S-02

**Pliki**: `app/Models/Playlist.php`, `app/Models/PlaylistItem.php`,
`app/Models/User.php`, `app/Actions/Playlists/ReplaceImportedPlaylist.php`

**Cel**: Oprzeć S-05 na scalonym kontrakcie finalnej gałęzi S-02 i dodać relacje
do przeglądów bez zmiany semantyki importu.

**Kontrakt**: Implementacja zaczyna się dopiero, gdy `feat/playlist-link-import`
jest w gałęzi bazowej. Fingerprint jest deterministycznie liczony z
uporządkowanych pól wpływających na eksport; nie zależy od ID wiersza,
`updated_at` ani samego `provider_revision`.

#### 2. Trwały model przeglądu

**Pliki**: `database/migrations/*_create_export_reviews_table.php`,
`database/migrations/*_create_export_review_items_table.php`,
`app/Models/ExportReview.php`, `app/Models/ExportReviewItem.php`,
`database/factories/ExportReviewFactory.php`,
`database/factories/ExportReviewItemFactory.php`

**Cel**: Utrwalić snapshot, cel, stan, wyniki i decyzje w formie, którą można
bezpiecznie wznowić po opuszczeniu strony i przekazać do S-06/S-07.

**Kontrakt**: `export_reviews` należy do użytkownika i playlisty; zapisuje target
provider, `linked|managed`, nullable FK konta, rozwiązaną tożsamość konta,
nullable market, source fingerprint, stan, typowany kod błędu, correlation ID,
`started_at`, `completed_at`, `expires_at`, `confirmed_at` i znacznik wysłania
powiadomienia. `export_review_items` zachowuje unikalną pozycję w review,
snapshot źródła, status dopasowania, pokazane dane i ID/URI celu oraz nullable
decyzję `keep|remove`. Usunięcie użytkownika lub playlisty kaskaduje; odłączenie
konta zeruje FK, lecz nie zmienia zapisanej tożsamości i unieważnia niepotwierdzony
cel linked przy ponownej walidacji.

#### 3. Typy i fingerprint

**Pliki**: `app/Enums/ExportReviewStatus.php`,
`app/Enums/ExportDestinationType.php`, `app/Enums/ExportMatchStatus.php`,
`app/Enums/ExportReviewDecision.php`,
`app/Actions/Playlists/FingerprintPlaylistContent.php`

**Cel**: Zamknąć język stanów i zapewnić porównywalny snapshot mimo reimportu,
duplikatów i zmian kluczy głównych.

**Kontrakt**: Statusy review to `queued|processing|ready|failed|confirmed|expired`;
matching to `matched|suspicious|unavailable`. Fingerprint obejmuje kolejność,
wystąpienia i znormalizowane dane źródłowe każdej pozycji. Klasy enum są jedynym
źródłem wartości aplikacyjnych; kolumny pozostają stringami zgodnymi z SQLite i
PostgreSQL.

#### 4. Tożsamość i market celu

**Pliki**: `database/migrations/*_add_market_to_streaming_accounts_table.php`,
`app/Models/StreamingAccount.php`,
`app/Integrations/StreamingAccounts/Data/StreamingIdentity.php`,
`app/Integrations/StreamingAccounts/SpotifyOAuthGateway.php`,
`app/Integrations/StreamingAccounts/Actions/LinkStreamingAccount.php`,
`app/Actions/ExportReviews/ResolveExportDestination.php`, `.env.example`,
`config/services.php`

**Cel**: Rozstrzygać dostępność Spotify dla rzeczywistego właściciela celu i
blokować tę samą parę provider–konto po stronie backendu.

**Kontrakt**: `StreamingAccount` otrzymuje nullable dwuliterowy market Spotify;
nowe link/relink zapisuje kraj z identity, a istniejące konto bez marketu jest
uzupełniane przez krótkotrwały dostęp przed startem review. Cel managed korzysta
z publicznej semantyki istniejących symbolicznych account ID oraz nowego
`SPOTIFY_TECHNICAL_MARKET`; wartości sekretne pozostają wyłącznie w runtime.
Tryb managed jest dozwolony tylko przy braku aktywnego linked account danego
providera. Dokładna zgodność target provider/account ze źródłem zwraca błąd
walidacji niezależnie od UI.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Migracje i rollback przechodzą na SQLite, a test migracji dowodzi relacji,
  indeksów, cascade/null semantics i braku kolumn na access token lub payload.
- Testy modeli i enumów dowodzą zamkniętych stanów, castów, fabryk oraz
  właścicielskich relacji.
- Test fingerprintu dowodzi stabilności dla tych samych danych oraz zmiany przy
  kolejności, duplikacie, usunięciu lub zmianie danych wpływających na eksport.
- Test destination resolvera pokrywa linked, managed, reconnect-required,
  brak marketu, tę samą parę źródłową i próbę użycia cudzego konta.
- Regresja importu S-02 i integracji S-04 pozostaje zielona.

#### Weryfikacja ręczna

- Przegląd schematu potwierdza, że zapisany snapshot wystarcza do późniejszego
  wykonania eksportu bez ponownego wyszukiwania i bez sekretów.
- Przegląd konfiguracji potwierdza wyłącznie symboliczne nazwy i semantykę
  wymaganych wartości runtime, bez szczegółów PaaS.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznego potwierdzenia modelu i granicy konfiguracji.

---

## Phase 2: Matching katalogowy i wykonanie w kolejce

### Przegląd

Ta faza przygotowuje wynik całej playlisty przez provider-neutralny kontrakt,
konserwatywną klasyfikację, cache i kontrolowany budżet YouTube.

### Wymagane zmiany

#### 1. Neutralny port katalogowy

**Pliki**: `app/Integrations/ExportMatching/Contracts/CatalogSearch.php`,
`app/Integrations/ExportMatching/Data/SourceTrack.php`,
`app/Integrations/ExportMatching/Data/CatalogCandidate.php`,
`app/Integrations/ExportMatching/Data/MatchResult.php`,
`app/Integrations/ExportMatching/MatchingFailure.php`

**Cel**: Oddzielić publiczne wyszukiwanie katalogu od OAuth użytkownika, probe i
zapisu playlist.

**Kontrakt**: Wejście zawiera snapshot źródła, provider i market celu; wyjście
zawiera dokładnie jeden wynik domenowy albo typowane niepowodzenie operacyjne.
DTO nie serializują tokenów ani surowych payloadów. `unavailable` wolno zwrócić
po poprawnej odpowiedzi katalogu bez wiarygodnego kandydata. Wyjątkiem jest
lokalny short-circuit dla niedostępnego źródła albo braku tytułu: nie wykonuje
requestu i musi być pokazany użytkownikowi jako problem danych źródłowych, nie
jako potwierdzony brak w katalogu celu.

#### 2. Klient i klasyfikator Spotify

**Pliki**: `app/Integrations/ExportMatching/Providers/SpotifyCatalogSearch.php`,
`app/Integrations/ExportMatching/SpotifyMatchClassifier.php`,
`app/Integrations/ExportMatching/SpotifyClientCredentials.php`

**Cel**: Dopasowywać Spotify bez zależności od grantu użytkownika i z
uwzględnieniem rynku docelowego.

**Kontrakt**: Access token Client Credentials istnieje tylko w pamięci pracy.
Wyszukiwanie najpierw używa ISRC, gdy jest dostępny, potem ograniczonych
kandydatów tytuł–wszyscy artyści; klasyfikator porównuje znormalizowane pola i
czas trwania. Mocna zgodność daje `matched`, niejednoznaczny najlepszy wynik
`suspicious`, a brak wiarygodnego kandydata `unavailable`. Timeouty są ograniczone
do istniejącego wzorca 5/10 sekund i nie mają ukrytego request-level retry.

#### 3. Klient i budżet YouTube

**Pliki**: `app/Integrations/ExportMatching/Providers/YouTubeCatalogSearch.php`,
`app/Integrations/ExportMatching/YouTubeMatchClassifier.php`,
`app/Integrations/ExportMatching/YouTubeSearchBudget.php`, `config/services.php`,
`.env.example`

**Cel**: Dopasowywać wyłącznie publiczne, użyteczne filmy bez częściowego
zużycia dziennego budżetu całej playlisty.

**Kontrakt**: Klient używa tego samego symbolicznego `YOUTUBE_API_KEY` co S-02,
nie grantu użytkownika. Przed jobem budżet pod blokadą oblicza unikalne cache
missy i rezerwuje je all-or-nothing względem konfigurowalnego limitu 100/dzień.
Jednakowe fingerprinty w tym samym celu są deduplikowane. Search jest
ograniczony do video i małej liczby kandydatów, a batch metadata potwierdza
dostępność oraz czas trwania. Cache przechowuje wyłącznie znormalizowany wynik
do 24 godzin; klucz i payload providera nie trafiają do logów ani bazy domenowej.

#### 4. Orkiestracja i trwały wynik

**Pliki**: `app/Actions/ExportReviews/StartExportReview.php`,
`app/Jobs/PrepareExportReview.php`,
`app/Providers/ExportMatchingServiceProvider.php`

**Cel**: Zapewnić idempotentny start, atomową publikację wyniku oraz bezpieczne
ponowienie bez mylenia awarii z niedostępnością.

**Kontrakt**: Start blokuje playlistę, liczy fingerprint, zwraca istniejący
aktywny review dla tego samego celu/snapshotu albo tworzy jeden `queued` i
dispatchuje job po commit. Job przechodzi `processing`, liczy wszystkie wyniki
w pamięci i publikuje je w jednej transakcji dopiero po sukcesie. Cache może
zachować świeże sukcesy między próbami; widoczny review nie pokazuje partial
state. Quota, rate limit, invalid response i temporary failure zapisują zamknięty
kod oraz correlation ID. Retry nie uruchamia się dla wyczerpanej kwoty i nie
przekracza ważności review.

#### 5. Powiadomienie długiej operacji

**Pliki**: `app/Notifications/ExportReviewCompleted.php`,
`resources/views/mail/export-review-completed.blade.php`

**Cel**: Poinformować użytkownika poza aplikacją tylko wtedy, gdy aktywne
oczekiwanie przekroczyło minutę.

**Kontrakt**: Terminalny `ready` albo `failed` po co najmniej 60 sekundach wysyła
jedną kolejkę mailową z nazwą platformy, neutralnym wynikiem i linkiem do
uwierzytelnionego właścicielskiego widoku; bez tytułów utworów, ID providerów,
query i correlation ID w temacie. Atomowy znacznik zapobiega duplikacji przy
retry.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy obu klientów z `Http::fake()` pokrywają request, bounds, timeout, 401,
  403, 429, quota, 5xx, zły JSON i brak wymaganych pól bez wycieku sekretów.
- Testy klasyfikatorów pokrywają exact ISRC, tytuł/artystów, tolerancję czasu,
  cover/remix/live, niejednoznaczność, brak wyniku oraz słabsze dane YouTube.
- Test budżetu dowodzi deduplikacji, cache hit, atomowej rezerwacji przed próbą,
  równoległego startu i czytelnej odmowy bez częściowego search.
- Test joba dowodzi atomowej publikacji, braku partial state, bezpiecznego retry,
  ochrony przed spóźnionym jobem i rozdzielenia awarii od `unavailable`.
- Test powiadomienia z kontrolowanym zegarem dowodzi braku maila poniżej 60
  sekund, jednego maila po progu oraz braku duplikatu przy ponowieniu joba.
- Żaden test automatyczny ani CI nie wykonuje rzeczywistego requestu providera.

#### Weryfikacja ręczna

- Przegląd fixture'ów potwierdza, że przykłady cover/remix/live są opisane jako
  podejrzane, a awarie katalogu nie pojawiają się jako brak utworu.
- Podgląd maila potwierdza bezpieczną treść i jednoznaczny powrót do wyniku bez
  ujawniania danych playlisty w temacie.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznego przeglądu klasyfikacji i treści powiadomienia.

---

## Phase 3: Przegląd, decyzje i świadome potwierdzenie

### Przegląd

Ta faza udostępnia właścicielski przepływ UI, kontrolę decyzji oraz niezmienny
handoff do przyszłego eksportu.

### Wymagane zmiany

#### 1. Trasy, żądania i autoryzacja

**Pliki**: `routes/web.php`,
`app/Http/Controllers/PlaylistExportReviewController.php`,
`app/Http/Requests/StartExportReviewRequest.php`,
`app/Http/Requests/ConfirmExportReviewRequest.php`

**Cel**: Udostępnić start, widok i potwierdzenie bez możliwości odczytu albo
mutacji cudzego banku.

**Kontrakt**: Nazwane trasy pod `auth,verified` obejmują POST startu dla
playlisty, GET właścicielskiego review, POST retry i POST potwierdzenia.
Kontroler zawsze wyszukuje playlistę/review przez relację zalogowanego
użytkownika; obce, usunięte i niespójne ID dają 404. Start i retry mają osobny
per-user/provider throttle, a wszystkie mutacje wymagają CSRF. Backend ponawia
walidację celu, konta, stanu, ważności i fingerprintu.

#### 2. Komponent stanu i decyzji

**Pliki**: `app/Livewire/ExportReviewPanel.php`,
`resources/views/livewire/export-review-panel.blade.php`,
`resources/views/export-reviews/show.blade.php`

**Cel**: Pokazać czytelny, dostępny i wznawialny przegląd bez nowej warstwy
JavaScript.

**Kontrakt**: Polling działa tylko dla `queued|processing` i zatrzymuje się w
stanie terminalnym. Po 30 sekundach pojawia się komunikat o dalszej pracy, po 60
sekundach informacja o bezpiecznym opuszczeniu strony i e-mailu. Każde
wystąpienie zachowuje kolejność i osobną decyzję. `matched` ma domyślne `keep`,
`suspicious` wymaga jawnego `keep|remove`, a `unavailable` pozwala zachować w
banku albo usunąć, lecz nigdy nie jest wliczane do eksportu. `aria-live` ogłasza
wyłącznie zmianę stanu, nie każde odpytywanie. Lokalny short-circuit dla
niedostępnego źródła albo braku tytułu jest odróżniony w copy od potwierdzonego
braku wiarygodnego kandydata w katalogu celu.

#### 3. Wejście z banku i podsumowanie celu

**Pliki**: `app/Http/Controllers/BankController.php`,
`resources/views/bank/index.blade.php`

**Cel**: Pozwolić rozpocząć lub wznowić kontrolę z właściwej karty playlisty i
przed pierwszym requestem pokazać właściciela celu.

**Kontrakt**: Karta pokazuje dozwolone platformy oraz dla każdej dokładnie jeden
aktywny tryb właściciela: linked albo managed. Ta sama para źródłowa jest
wyłączona z wyjaśnieniem. Istniejący review jest wznawiany zamiast duplikowany;
stan failed/expired oferuje bezpieczne przygotowanie nowego wyniku.

#### 4. Atomowe potwierdzenie i handoff

**Pliki**: `app/Actions/ExportReviews/ConfirmExportReview.php`,
`app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php`

**Cel**: Zagwarantować, że późniejszy eksport wykona dokładnie treść pokazaną i
zaakceptowaną przez użytkownika.

**Kontrakt**: Akcja pod blokadą sprawdza właściciela, `ready`, TTL, dokładny cel,
fingerprint oraz komplet decyzji. Pusty wynik jest odrzucany. Wybrane `remove`
odtwarza uporządkowane pozycje banku atomowo; niedostępne `keep` pozostają w
banku, ale nie w manifeście. Sukces ustawia review na `confirmed` dokładnie raz.
DTO manifestu udostępnia S-06/S-07 read-only: review ID, playlist ID, target
provider/ownership/account/market, source fingerprint po potwierdzeniu oraz
uporządkowane dokładne target ID/URI. Nie udostępnia metody ponownego search.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy tras pokrywają metody, URI, middleware, throttle, guest, unverified,
  cross-user 404, CSRF oraz backendowe odrzucenie niedozwolonego celu.
- Testy Livewire pokrywają polling i progi czasu, wszystkie trzy klasy,
  niezależne duplikaty, komplet decyzji, retry i zatrzymanie pollingu.
- Test potwierdzenia pokrywa atomowe usunięcie/reindeksację, pozostawienie
  unavailable w banku, dokładny manifest, pusty wynik i idempotentny double submit.
- Test wyścigów pokrywa reimport/edycję, odłączenie konta, wygaśnięcie,
  równoległe karty i spóźniony job; żaden stale review nie zostaje potwierdzony.
- Test handoffu dowodzi, że S-06/S-07 mogą odczytać wyłącznie potwierdzony,
  uporządkowany manifest bez ponownego matchingu.

#### Weryfikacja ręczna

- Na małym ekranie i desktopie użytkownik rozumie provider, właściciela celu,
  trzy klasy wyników oraz liczbę eksportowanych, pomijanych i usuwanych pozycji.
- Klawiatura, widoczny focus, 200% zoom, długie tytuły i komunikaty screen readera
  pozwalają przejść cały przepływ bez niezamierzonych zmian.
- Odświeżenie, Back i powrót z banku wznawiają właściwy przegląd, a podwójne
  kliknięcie nie tworzy drugiej operacji ani potwierdzenia.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznej akceptacji kompletnego UX.

---

## Phase 4: Odporność, regresja i wydanie

### Przegląd

Ta faza zamyka macierz obu baz danych, jakość repozytorium i bezpieczny proces
wydania zmiany schematu bez uzależniania aplikacji od wnętrza PaaS.

### Wymagane zmiany

#### 1. Kompletna macierz testowa

**Pliki**: `tests/Unit/Integrations/ExportMatching/`,
`tests/Feature/ExportMatchReview/`, `tests/Feature/Playlists/`,
`tests/Feature/StreamingAccounts/`

**Cel**: Skonsolidować testy happy path, failure taxonomy, bezpieczeństwa,
wydajności i regresji S-02/S-04.

**Kontrakt**: Fixture'y pokrywają 0, 1 i 20 pozycji, duplikaty, unavailable,
cover/remix/live, wszystkie suspicious, pusty manifest, cache/budżet, progi
30/60 sekund, retry oraz wyścigi. Wszystkie provider calls są fake'owane; guard
testowy odrzuca nieprzechwycony ruch sieciowy.

#### 2. Bramka repozytorium i PostgreSQL

**Pliki**: `.github/workflows/ci.yml` tylko jeśli istniejąca macierz nie obejmuje
nowych testów, `scripts/verify-source-contract` tylko jeśli wymaga rejestracji
nowych publicznych plików

**Cel**: Dowieść, że addytywne migracje i cały przepływ działają w SQLite oraz
produkcyjnym silniku PostgreSQL bez osłabienia istniejących kontroli.

**Kontrakt**: Istniejący job PostgreSQL wykonuje `migrate:fresh` i pełny zestaw;
nie dodajemy sekretów ani live API do CI. Wydanie schematu korzysta wyłącznie z
opublikowanej zdolności PaaS do zastosowania zatwierdzonych migracji; plan nie
opisuje transportu, host paths, blokad ani rollback internals Managera.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Targetowane testy S-05 przechodzą: `php artisan test tests/Unit/Integrations/ExportMatching tests/Feature/ExportMatchReview`.
- Regresje S-02 i S-04 przechodzą: `php artisan test tests/Feature/Playlists tests/Feature/StreamingAccounts`.
- Pełny zestaw przechodzi: `composer test`.
- Formatowanie przechodzi: `vendor/bin/pint --test`.
- Produkcyjny frontend buduje się: `npm run build`.
- Kontrakt źródła przechodzi: `npm run check:source`.
- Audyty zależności przechodzą: `composer audit --locked --no-interaction` oraz
  `npm audit --audit-level=high`.
- CI PostgreSQL przechodzi z pełnym `migrate:fresh` i bez rzeczywistych requestów
  providerów.

#### Weryfikacja ręczna

- Lokalny scenariusz z deterministycznym fake katalogu przechodzi od karty banku
  przez oczekiwanie, decyzje i potwierdzenie do inspekcji frozen manifestu.
- Ręcznie potwierdzono copy błędów, e-mail po 60 sekundach i brak maila dla
  krótszej operacji bez wywoływania prawdziwych API.
- Przegląd bazy, cache, wygenerowanego HTML i logów nie znajduje access tokenów,
  API key, client secret, surowych payloadów ani pełnych query katalogowych.
- Zatwierdzony addytywny zestaw migracji jest gotowy do osobnego, nadzorowanego
  wydania schematu zgodnie z publiczną granicą PaaS.

**Uwaga implementacyjna**: Zakończ zmianę dopiero po ręcznej akceptacji UX,
bezpieczeństwa danych i gotowości addytywnych migracji.

---

## Strategia testowania

### Testy jednostkowe

- Deterministyczny fingerprint uporządkowanych pozycji, w tym duplikatów i
  placeholderów.
- Normalizacja tytułów i artystów, mocne/podejrzane/brak dopasowania oraz
  providerowe różnice danych.
- Exact request/response kontrakty Spotify i YouTube przez `Http::fake()`.
- Cache, deduplikacja i atomowy dzienny budżet przy współbieżności.
- Zamknięte mapowanie błędów i redakcja danych wrażliwych.

### Testy integracyjne

- Start → kolejka → ready/failed → retry → potwierdzenie na modelach S-02.
- Własność tras i rekordów, ten sam source target, odłączone konto i brak marketu.
- Atomiczność potwierdzenia, ponowna numeracja banku i dokładny frozen manifest.
- Reimport/edycja podczas joba lub review, expiry oraz double submit.
- Powiadomienie po 60 sekundach z `Notification::fake()` i kontrolowanym zegarem.
- Pełna macierz na SQLite i PostgreSQL; bez live providerów.

### Kroki testowania ręcznego

1. Załaduj lokalne fixture'y Spotify→YouTube i YouTube→Spotify z wynikiem każdej
   klasy, duplikatem oraz długimi nazwami.
2. Uruchom review, sprawdź komunikaty przed 30 sekundami, po 30 i po 60 oraz
   możliwość opuszczenia i wznowienia ekranu.
3. Zaakceptuj podejrzany wynik, pozostaw unavailable w banku, usuń inną pozycję i
   sprawdź dokładne podsumowanie przed potwierdzeniem.
4. Zmień playlistę w drugiej karcie i potwierdź, że stare review wymaga nowego
   matchingu.
5. Potwierdź świeży review, sprawdź kolejność banku oraz niezmienny manifest dla
   S-06/S-07; ponowne potwierdzenie ma być bezpiecznie idempotentne.
6. Powtórz widok klawiaturą, przy 200% zoom i na małym ekranie; zweryfikuj jeden
   e-mail dla sztucznie wydłużonej operacji.

## Uwagi dotyczące wydajności

Zakres jest ograniczony do 20 pozycji, więc fingerprint i transakcje działają
liniowo i nie wymagają dodatkowej infrastruktury. Job nie powinien wykonywać
nieograniczonej równoległości: requesty muszą respektować budżet, timeout
workera i rate limiting. Cache po znormalizowanym fingerprintcie redukuje
powtarzane wyszukiwania, ale nie może mieszać marketów Spotify ani właścicieli
celu. Pomiar czasu zapisuje wyłącznie stan i bezpieczne czasy rozpoczęcia/
zakończenia; nie loguje zapytań utworów.

## Uwagi dotyczące migracji

Migracje są addytywne: nowe tabele review oraz nullable market konta nie
wymagają usuwania ani przekształcania istniejących playlist. Istniejące Spotify
account z `market = null` jest uzupełniane leniwie przy pierwszej próbie użycia,
bez sieci w migracji. Rollback aplikacji przed S-05 nie może konsumować nowych
review, dlatego S-06/S-07 powstają dopiero po zaakceptowaniu tego kontraktu.
Schema-changing release pozostaje osobną nadzorowaną operacją PaaS; aplikacja
nie implementuje jej mechaniki.

## Referencje

- PRD: `context/foundation/prd.md` — US-01, FR-007, FR-008, NFR-001, NFR-002.
- Roadmapa: `context/foundation/roadmap.md:142` — S-05 i jego granice względem
  S-06/S-07.
- Wymaganie wstępne: `feat/playlist-link-import` oraz
  `context/changes/playlist-link-import/plan.md` po scaleniu S-02.
- Wzorzec własności: `app/Http/Controllers/StreamingAccountController.php:22`.
- Granica tokenu: `app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php:27`.
- Kolejka produkcyjna: `config/queue.php:16`, `Dockerfile:115`.
- Testowa kolejka sync: `phpunit.xml:20`.
- Spotify Search API: `https://developer.spotify.com/documentation/web-api/reference/search`.
- Spotify Client Credentials:
  `https://developer.spotify.com/documentation/web-api/tutorials/client-credentials-flow`.
- YouTube `search.list`: `https://developers.google.com/youtube/v3/docs/search/list`.
- YouTube `videos.list`: `https://developers.google.com/youtube/v3/docs/videos/list`.
- Polityka danych YouTube:
  `https://developers.google.com/youtube/terms/developer-policies`.
- Granica zewnętrznego PaaS: globalna umiejętność `s-manager-use`; w planie
  zachowano wyłącznie minimalny kontrakt konsumenta.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Kontrakt przeglądu i wersjonowanie zawartości

#### Automated

- [x] 1.1 Migracje i rollback przechodzą na SQLite wraz z relacjami i semantyką usuwania — c44310d
- [x] 1.2 Modele, enumy i fabryki zachowują zamknięty kontrakt właścicielski — c44310d
- [x] 1.3 Fingerprint wykrywa każdą zmianę uporządkowanej zawartości — c44310d
- [x] 1.4 Destination resolver pokrywa właściciela, market i zakazaną parę źródłową — c44310d
- [x] 1.5 Regresje S-02 i S-04 pozostają zielone — c44310d

#### Manual

- [x] 1.6 Schema review potwierdza kompletny handoff bez sekretów — 4216441
- [x] 1.7 Konfiguracja zachowuje minimalną publiczną granicę PaaS — 4216441

### Phase 2: Matching katalogowy i wykonanie w kolejce

#### Automated

- [x] 2.1 Klienci katalogowi przechodzą pełną macierz fake HTTP bez wycieków — 0c5fe7a
- [x] 2.2 Klasyfikatory rozróżniają matched, suspicious i unavailable — 0c5fe7a
- [x] 2.3 Budżet YouTube atomowo rezerwuje cache missy i deduplikuje zapytania — 0c5fe7a
- [x] 2.4 Job publikuje wyłącznie kompletny wynik i bezpiecznie obsługuje retry — 0c5fe7a
- [x] 2.5 Powiadomienie po 60 sekundach jest pojedyncze i idempotentne — 0c5fe7a
- [x] 2.6 Testy i CI nie wykonują rzeczywistych requestów providerów — 0c5fe7a

#### Manual

- [x] 2.7 Fixture'y potwierdzają konserwatywne traktowanie cover, remix i live — 4216441
- [x] 2.8 Podgląd e-maila jest czytelny i nie ujawnia danych playlisty — 4216441

### Phase 3: Przegląd, decyzje i świadome potwierdzenie

#### Automated

- [x] 3.1 Trasy egzekwują middleware, CSRF, throttle i owner-scoped 404 — 704f895
- [x] 3.2 Livewire poprawnie obsługuje polling, progi czasu i trzy klasy wyników — 704f895
- [x] 3.3 Potwierdzenie atomowo aktualizuje bank i zamraża dokładny manifest — 704f895
- [x] 3.4 Pusty wynik, niekompletne decyzje i double submit są bezpiecznie odrzucane — 704f895
- [x] 3.5 Reimport, edycja, unlink, expiry i spóźniony job unieważniają stale review — 704f895
- [x] 3.6 Handoff udostępnia wyłącznie potwierdzony manifest bez ponownego matchingu — 704f895

#### Manual

- [x] 3.7 Responsywny ekran jednoznacznie pokazuje cel, właściciela i skutki decyzji
- [x] 3.8 Przepływ jest dostępny klawiaturą, przy 200% zoom i dla czytnika ekranu
- [x] 3.9 Odświeżenie, Back i dwie karty nie duplikują ani nie gubią operacji

### Phase 4: Odporność, regresja i wydanie

#### Automated

- [x] 4.1 Targetowane testy S-05 przechodzą — d29954f
- [x] 4.2 Regresje playlist i integracji streamingowych przechodzą — d29954f
- [x] 4.3 Pełny zestaw Composer przechodzi — d29954f
- [x] 4.4 Laravel Pint przechodzi bez zmian — d29954f
- [x] 4.5 Produkcyjny frontend buduje się — d29954f
- [x] 4.6 Kontrakt źródła i audyty zależności przechodzą — d29954f
- [x] 4.7 Pełna macierz PostgreSQL przechodzi bez live providerów — d29954f

#### Manual

- [x] 4.8 Lokalny fake E2E kończy się frozen manifestem zgodnym z przeglądem
- [x] 4.9 Copy, progi czasu i pojedynczy e-mail są zaakceptowane
- [x] 4.10 Baza, cache, HTML i logi nie zawierają sekretów ani provider payloadów
- [x] 4.11 Addytywny zestaw migracji jest gotowy do nadzorowanego wydania schematu
