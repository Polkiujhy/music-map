# Edycja playlisty w banku — plan implementacji

## Przegląd

S-03 udostępnia właścicielowi osobny ekran zawartości playlisty zapisanej w
banku. Użytkownik może zmienić kolejność istniejących wystąpień przyciskami
góra/dół, usunąć wybrane wystąpienia i zapisać cały szkic atomowo. Zakres nie
obejmuje dodawania utworów ani modyfikowania danych pochodzących od providera.

Zmiana ustanawia neutralny fingerprint zawartości używany do optimistic
concurrency i później przez S-05. Lokalna edycja jest niezależna od modeli
przeglądu eksportowego: zapis zawsze może się udać, natomiast S-05 odrzuca
przegląd przygotowany dla wcześniejszego fingerprintu. Automatyczny refresh
YouTube uzgadnia zachowane wystąpienia po `occurrence_id`, nie przywraca
usuniętych pozycji i nie zmienia lokalnej kolejności.

## Analiza stanu obecnego

S-02 jest ukończone i zarchiwizowane. `Playlist` przechowuje trwałe pochodzenie
źródła, a `PlaylistItem` reprezentuje uporządkowane wystąpienie, nie globalny
utwór. Relacja `Playlist::items()` sortuje po `position`, a baza wymusza
unikalność `(playlist_id, position)`. Duplikaty `catalog_id` i niedostępne
placeholdery są prawidłowymi elementami playlisty.

Jawny reimport i udany refresh YouTube korzystają dziś z
`ReplaceImportedPlaylist`, który pod blokadą usuwa i odtwarza wszystkie wiersze
elementów. Identyfikator `playlist_item.id` nie jest więc trwałą tożsamością.
Osobna komenda retencji usuwa elementy i metadane po 30 dniach, jeżeli nie udało
się potwierdzić świeżości danych YouTube.

Bank ma jeden duży widok z kartami playlist. Nie istnieje ekran szczegółów ani
własny komponent Livewire. Livewire 4 i Flux 2 są jednak zainstalowane, a layout
ładuje ich zasoby. Plan S-05 w sąsiednim worktree przewiduje własny komponent
`ExportReviewPanel` oraz zmiany w `Playlist`, routingu i karcie banku.

## Pożądany stan końcowy

Właściciel otwiera playlistę ze swojego banku, widzi od zera do 20 wystąpień w
dokładnej kolejności, w tym duplikaty i jawnie opisane placeholdery. Przygotowuje
zmiany lokalnie, a jeden zapis atomowo usuwa wybrane wiersze i nadaje pozostałym
ciągłe pozycje `0..n-1`. Usunięcie ostatniej pozycji wymaga dodatkowego
potwierdzenia; pusta playlista pozostaje prawidłowym rekordem banku, lecz S-05
nie pozwala wyeksportować pustego manifestu.

Każdy formularz niesie fingerprint pokazanej zawartości. Jeśli reimport,
refresh, inna karta albo potwierdzenie S-05 zmieniły playlistę, zapis nie wykonuje
żadnej częściowej mutacji i prosi o ponowne załadowanie bieżącej wersji. Zapis
rzeczywistej lokalnej zmiany ustawia `bank_content_edited_at`; no-op go nie
ustawia ani nie przesuwa.

Automatyczny refresh lokalnie edytowanej playlisty YouTube aktualizuje dane
zachowanych wystąpień po `occurrence_id`, nie dodaje nowych wystąpień ze źródła,
nie przywraca usuniętych i nie zmienia lokalnej kolejności. Jawny reimport po
ostrzeżeniu zastępuje całą zawartość wersją platformy i zeruje znacznik lokalnej
edycji.

### Kluczowe odkrycia

- Pochodzenie playlisty jest utrwalone w `app/Models/Playlist.php:13`, a lokalna
  edycja nie może zmieniać `source_provider`, `source_playlist_id`,
  `source_account_id`, `canonical_source_url`, `provider_revision` ani
  `streaming_account_id`.
- Elementy są wystąpieniami i mogą powtarzać `catalog_id`; jedynym trwałym
  porządkiem jest `position` (`database/migrations/2026_09_13_010100_create_playlist_items_table.php:13`).
- Reimport odtwarza dzieci (`app/Actions/Playlists/ReplaceImportedPlaylist.php:44`),
  więc row ID wolno użyć wyłącznie jako krótkotrwałej kontroli członkostwa, nie
  jako tożsamości domenowej lub składnika fingerprintu.
- Plan S-05 wymaga snapshotu i fingerprintu niezależnego od row ID
  (`context/changes/export-match-review/plan.md:181` w równoległym worktree S-05). Neutralny
  kontrakt dostarczony przez S-03 usuwa potrzebę drugiej implementacji hasha.
- `BankController` nie musi się zmieniać; osobny właścicielski ekran ogranicza
  wspólny diff z S-05 do addytywnej trasy i jednego linku w karcie banku.
- Udany refresh YouTube przechodzi przez pełną podmianę snapshotu
  (`app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php:30`), a cleanup po
  30 dniach usuwa elementy osobno
  (`app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php:53`). Obie ścieżki
  muszą zmieniać fingerprint widziany przez S-05.

## Czego NIE robimy

- Nie dodajemy nowych utworów, wyszukiwania katalogowego ani ręcznych zamienników.
- Nie edytujemy nazwy, opisu, twórców, albumu, czasu, ISRC, providerowych ID/URI,
  dostępności ani żadnego pola pochodzenia.
- Nie tworzymy playlist od zera i nie usuwamy całego rekordu playlisty.
- Nie implementujemy synchronizacji ręcznej lub dwukierunkowej, harmonogramu
  S-08, reguły konfliktu platforma–bank ani widoku różnic S-09.
- Nie implementujemy modeli, stanów, UI, matchingów ani potwierdzenia eksportu
  należących do S-05.
- Nie zmieniamy `BankController`, `User`, `PlaylistItem`, providerowych readerów,
  konfiguracji integracji ani publicznego kontraktu PaaS.
- Nie wykonujemy rzeczywistych requestów Spotify lub YouTube w testach S-03.

## Podejście do implementacji

S-03 doda neutralną klasę `FingerprintPlaylistContent` pod domeną playlist.
Fingerprint będzie wersjonowanym SHA-256 kanonicznego JSON i obejmie pola
playlisty wpływające na przyszły eksport (`name`, `description`, provider i ID
źródła) oraz uporządkowane dane wszystkich wystąpień. Nie obejmie bazodanowych
ID, timestampów, `streaming_account_id`, `provider_revision` ani samego
`bank_content_edited_at`.

Akcja `UpdateBankPlaylistItems` rozpocznie lookup od `User::playlists()`, zablokuje
playlistę, porówna oczekiwany fingerprint oraz pełną, unikalną listę bieżących
row ID i dopiero wtedy zastosuje usunięcia oraz kolejność. Dwustopniowe
przeindeksowanie przez bezkolizyjny zakres tymczasowy ochroni unique constraint.
Zmiana pozostanie jednym commitem bazodanowym; konflikt albo błąd pozostawia
poprzedni stan bez zmian.

Nowy `PlaylistEditor` w Livewire utrzyma szkic tylko w pamięci komponentu.
Przyciski góra/dół i usuń zmienią szkic, a dopiero „Zapisz zmiany” wywoła akcję.
Oddzielna, właścicielska strona zachowa pochodzenie jako read-only i pozostawi
kartę banku niemal bez zmian.

Refresh YouTube rozgałęzi zapis dopiero po pobraniu kompletnego, poprawnego
snapshotu. Playlisty bez lokalnej edycji nadal użyją pełnego replacementu.
Playlisty edytowane zostaną uzgodnione po `occurrence_id`: zachowane wiersze
otrzymają świeże pola, brakujące u źródła zostaną bezpiecznymi placeholderami,
a nowe wystąpienia źródłowe będą ignorowane do jawnego reimportu lub S-08.

## Krytyczne szczegóły implementacji

### Sekwencjonowanie stanu

Każda mutacja blokuje najpierw playlistę. S-05 po integracji zachowuje kolejność
blokad `playlist → export review`, ponownie liczy ten sam fingerprint przed
publikacją joba i potwierdzeniem oraz traktuje mismatch jako `expired` z przyczyną
`source_changed`. S-03 nie odpytuje tabel S-05 i nie unieważnia review bezpośrednio.

### Czas i cykl życia

Automatyczne uzgodnienie nie może ustawić `provider_revision` na rewizję pełnego
źródła, skoro bank zachował własną projekcję kolejności i usunięć. Może natomiast
zaktualizować `provider_metadata_refreshed_at` po odświeżeniu każdego zachowanego
wystąpienia. Jeżeli odświeżenie nie powiedzie się do granicy retencji, istniejący
cleanup nadal usuwa dane; po takim purge zeruje również `bank_content_edited_at`.

## Phase 1: Kontrakt zawartości i atomowa mutacja

### Przegląd

Faza ustanawia neutralny fingerprint, minimalny znacznik lokalnej edycji oraz
bezpieczną akcję zmiany kolejności i usuwania pozycji.

### Wymagane zmiany

#### 1. Znacznik lokalnej edycji

**Pliki**: `database/migrations/*_add_bank_content_edited_at_to_playlists_table.php`,
`app/Models/Playlist.php`, `database/factories/PlaylistFactory.php`

**Cel**: Rozróżnić nietknięty snapshot źródłowy od bankowej projekcji użytkownika
bez zmiany kontraktu pochodzenia.

**Kontrakt**: Addytywna nullable kolumna timestamp
`playlists.bank_content_edited_at`; model castuje ją do immutable datetime.
Rzeczywista lokalna zmiana ustawia bieżący czas, no-op pozostawia wartość bez
zmian, a pełny jawny reimport i purge pustego shellu zerują ją.

#### 2. Kanoniczny fingerprint

**Pliki**: `app/Actions/Playlists/FingerprintPlaylistContent.php`,
`tests/Unit/Playlists/FingerprintPlaylistContentTest.php`

**Cel**: Zapewnić jeden provider-neutralny stempel treści dla formularza S-03 i
snapshotu S-05.

**Kontrakt**: SHA-256 wejścia oznaczonego `playlist-content:v1`. Kanoniczna lista
obejmuje `name`, `description`, `source_provider`, `source_playlist_id`, a dla
każdej pozycji w kolejności: ordinal, `occurrence_id`, `catalog_id`, `catalog_uri`,
`title`, uporządkowane `creators`, `album`, `duration_milliseconds`, `isrc` i
`is_available`. Serializacja ma stałą kolejność pól, UTF-8 oraz
`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR`. Row ID,
timestampy i dane poświadczeń są wykluczone.

#### 3. Atomowa aktualizacja wystąpień

**Pliki**: `app/Actions/Playlists/UpdateBankPlaylistItems.php`,
`app/Actions/Playlists/PlaylistEditConflict.php`,
`tests/Feature/PlaylistEditing/UpdateBankPlaylistItemsTest.php`

**Cel**: Zastosować cały szkic albo nic, zachowując duplikaty, providerowe dane i
ciągłą kolejność.

**Kontrakt**: Akcja przyjmuje właściciela, playlist ID, oczekiwany fingerprint,
uporządkowaną listę unikalnych bieżących item ID oraz jawne potwierdzenie pustego
wyniku. Pod blokadą odrzuca obcą playlistę, fingerprint mismatch, ID spoza
playlisty, duplikat ID, więcej niż 20 pozycji i niepotwierdzony pusty wynik.
Dozwolona lista jest podzbiorem pełnego bieżącego snapshotu. Pozostałe rekordy
zachowują wszystkie pola poza `position`; zmieniona lista ustawia
`bank_content_edited_at`. Zamiana pozycji nie narusza unique constraint nawet
przejściowo.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Addytywna migracja stosuje się i cofa na izolowanym SQLite, a model poprawnie
  castuje znacznik.
- Fingerprint jest stabilny dla identycznej treści, ignoruje row ID i timestampy
  oraz zmienia się po zmianie kolejności, duplikatu, placeholdera lub pola
  wpływającego na eksport.
- Akcja zachowuje duplikaty i dane wystąpień, nadaje pozycje `0..n-1`, wspiera
  potwierdzony pusty wynik i nie zmienia bazy przy konflikcie albo błędzie.
- Test wyścigu PostgreSQL dowodzi, że dwa równoległe zapisy nie powodują silent
  lost update ani naruszenia unique constraint.
- Targetowane testy przechodzą:
  `php artisan test tests/Unit/Playlists/FingerprintPlaylistContentTest.php tests/Feature/PlaylistEditing/UpdateBankPlaylistItemsTest.php`.
- Formatowanie i manifest źródła przechodzą:
  `vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Przegląd schematu potwierdza jedną nullable kolumnę bez duplikowania modeli lub
  stanu S-05.
- Przegląd kontraktu potwierdza, że żadne pole pochodzenia ani providerowe pole
  zachowanego wystąpienia nie jest edytowalne.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznej akceptacji fingerprintu jako wspólnego kontraktu S-03/S-05.

---

## Phase 2: Właścicielski edytor playlisty

### Przegląd

Faza dostarcza osobny, dostępny ekran przeglądania i przygotowania atomowego
zapisu przy minimalnej zmianie istniejącej karty banku.

### Wymagane zmiany

#### 1. Właścicielska trasa i ekran

**Pliki**: `routes/web.php`,
`app/Http/Controllers/PlaylistEditingController.php`,
`resources/views/playlists/edit.blade.php`

**Cel**: Udostępnić edytor wyłącznie właścicielowi bez rozszerzania zapytania
`BankController` i bez ujawniania istnienia cudzej playlisty.

**Kontrakt**: Jedna nazwana trasa GET `bank.playlists.edit` pod istniejącym
`auth,verified`. Kontroler przyjmuje parametr jako string i rozpoczyna lookup od
`$request->user()->playlists()`; obce, usunięte i niepoprawne ID dają 404.
Wrapper pokazuje pochodzenie i canonical source link jako read-only oraz osadza
komponent edytora.

#### 2. Szkic i zapis Livewire

**Pliki**: `app/Livewire/PlaylistEditor.php`,
`resources/views/livewire/playlist-editor.blade.php`

**Cel**: Umożliwić edycję do 20 wystąpień bez własnego JavaScriptu i bez zapisu
każdego ruchu osobno.

**Kontrakt**: `mount` i `save` wykonują owner-scoped lookup. Komponent przechowuje
uporządkowaną listę item ID oraz fingerprint początkowy. Przyciski góra/dół
zmieniają wyłącznie szkic, mają opisujące pozycję `aria-label` i są wyłączone na
granicach. Usunięcie pozycji zmienia szkic; usunięcie ostatniej wymaga osobnego
potwierdzenia Flux. Jeden przycisk zapisuje całość przez akcję fazy 1. Konflikt
pokazuje `role="alert"`, nie zapisuje nic i udostępnia powrót do aktualnej wersji.
Sukces ogłasza `role="status"` i odświeża fingerprint.

#### 3. Minimalne wejście z banku

**Plik**: `resources/views/bank/index.blade.php`

**Cel**: Udostępnić wejście do szczegółów bez przebudowy karty, którą rozszerzy
równoległe S-05.

**Kontrakt**: Jedna addytywna kontrolka „Przeglądaj i edytuj” obok linku źródła.
Nie wydzielamy komponentu karty, nie dokładamy danych do `BankController` i nie
renderujemy żadnego stanu eksportu. Wygasły, wyczyszczony shell YouTube może
otworzyć ekran z pustą listą oraz komunikatem o konieczności reimportu.

#### 4. Autoryzacja i zachowanie UI

**Pliki**: `tests/Feature/PlaylistEditing/PlaylistEditingAuthorizationTest.php`,
`tests/Feature/PlaylistEditing/PlaylistEditorTest.php`

**Cel**: Dowieść izolacji właściciela oraz zachowania szkicu bez mieszania testów
S-02 i S-05.

**Kontrakt**: Testy pokrywają guest, unverified, cross-user 404, usunięty rekord,
duplikaty, placeholdery, ruchy graniczne, usunięcia, potwierdzenie ostatniej
pozycji, pojedynczy zapis, no-op i konflikt po zmianie w drugiej karcie.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Trasa ma stabilne URI/nazwę, `auth,verified` i owner-scoped 404.
- Testy Livewire pokrywają szkic, przyciski graniczne, duplikaty, placeholdery,
  potwierdzenie pustej listy, sukces oraz konflikt bez częściowego zapisu.
- Bank nadal pokazuje wyłącznie playlisty właściciela i zawiera dokładnie jedno
  wejście do edytora na każdej karcie.
- Targetowane testy przechodzą:
  `php artisan test tests/Feature/PlaylistEditing tests/Feature/BankAccessTest.php`.
- Frontend, formatowanie i manifest przechodzą:
  `npm run build && vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.

#### Weryfikacja ręczna

- Na ekranie wąskim i szerokim użytkownik rozpoznaje źródło, kolejność, duplikaty,
  placeholdery, stan szkicu i moment faktycznego zapisu.
- Klawiatura, widoczny focus, 200% zoom oraz czytnik ekranu pozwalają zmieniać
  kolejność, usuwać i potwierdzić pustą playlistę.
- Opuszczenie strony przed zapisem nie mutuje bazy, a zapis konfliktowego szkicu
  jasno prosi o ponowne załadowanie.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznej akceptacji kompletnego UX edytora.

---

## Phase 3: Refresh i świadomy reimport

### Przegląd

Faza chroni lokalne zmiany przed procesami S-02, zachowując istniejący cykl
świeżości danych YouTube i jednoznaczną semantykę jawnego reimportu.

### Wymagane zmiany

#### 1. Uzgodnienie edytowanego snapshotu YouTube

**Pliki**: `app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php`,
`app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php`,
`tests/Feature/PlaylistEditing/YouTubeEditedPlaylistRefreshTest.php`

**Cel**: Odświeżyć providerowe dane zachowanych wystąpień bez cofania lokalnej
kolejności i usunięć.

**Kontrakt**: Po pełnym readzie, ale przed zapisem, refresh wybiera pełny
replacement dla `bank_content_edited_at = null` albo uzgodnienie dla edytowanej
playlisty. Uzgodnienie pod blokadą ponownie sprawdza znacznik i mapuje każde
biezące wystąpienie po niepustym `occurrence_id`. Zachowuje row ID i kolejność,
aktualizuje świeże pola wystąpień, a brakujące u źródła czyści do jawnego
placeholdera. Nie dodaje wystąpień istniejących tylko w nowym źródle, nie zeruje
znacznika i nie przesuwa `provider_revision`; po kompletnym sukcesie aktualizuje
dozwolone metadane playlisty oraz `provider_metadata_refreshed_at`. Brak
wiarygodnego `occurrence_id` kończy próbę typowanym niepowodzeniem zamiast
częściowej publikacji.

#### 2. Pełny jawny reimport

**Pliki**: `app/Actions/Playlists/ImportPlaylist.php`,
`app/Integrations/PlaylistImport/ImportFailureCode.php`,
`app/Http/Controllers/PlaylistImportController.php`,
`app/Http/Controllers/PlaylistReimportController.php`, `routes/web.php`,
`resources/views/playlists/edit.blade.php`,
`tests/Feature/PlaylistEditing/EditedPlaylistReimportTest.php`

**Cel**: Pozwolić świadomie wrócić do pełnej wersji platformy bez przypadkowego
skasowania lokalnej pracy przez ogólny formularz importu.

**Kontrakt**: Dedykowany POST `bank.playlists.reimport` pod `auth,verified`, CSRF
i istniejącym limitem importu przyjmuje jawne potwierdzenie oraz owner-scoped
playlist ID; URL pochodzi wyłącznie z zapisanej `canonical_source_url`. Dla
lokalnie edytowanej playlisty ogólny import tego samego źródła zwraca przed
requestem providera zamknięty kod `local_edits_confirmation_required`, mapowany
przez kontroler na stały komunikat kierujący do właścicielskiego edytora.
Dedykowany reimport pokazuje w modalu, że zastąpi kolejność i usunięcia, a po
potwierdzonym, kompletnym replacementcie zeruje `bank_content_edited_at`.

#### 3. Cleanup po granicy retencji

**Pliki**: `app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php`,
`tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php`

**Cel**: Zachować dotychczasową politykę wygasania bez pozostawienia fałszywego
stanu lokalnej edycji.

**Kontrakt**: Jeżeli refresh nie powiedzie się do 30 dni, istniejąca atomowa
ścieżka nadal usuwa providerowe pola i wszystkie elementy. W tej samej transakcji
zeruje `bank_content_edited_at`. Powstała pusta zawartość ma inny fingerprint,
więc formularz S-03 i review S-05 stają się nieaktualne bez bezpośredniej
zależności od ich modeli.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Refresh edytowanej playlisty zachowuje lokalną kolejność i usunięcia, odświeża
  retained occurrences po `occurrence_id`, tworzy placeholder dla zniknięcia i
  nie dopisuje nowych elementów źródła.
- Refresh nietkniętej playlisty nadal wykonuje pełny atomowy replacement, a
  awaria pozostawia wcześniejszy snapshot i świeżość bez zmian.
- Ogólny reimport lokalnie edytowanego źródła wykonuje zero requestów; dedykowany
  potwierdzony reimport zastępuje snapshot i zeruje znacznik.
- Cleanup po 30 dniach usuwa dane i znacznik w jednej transakcji, a jego
  fingerprint różni się od stanu sprzed purge.
- Targetowane regresje przechodzą:
  `php artisan test tests/Feature/PlaylistEditing tests/Feature/Playlists/PlaylistImportTest.php tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php tests/Feature/Playlists/SpotifyPlaylistImportTest.php`.
- Żaden test nie wykonuje rzeczywistego requestu providerów.

#### Weryfikacja ręczna

- Użytkownik rozumie różnicę między automatycznym odświeżeniem danych a pełnym,
  świadomym reimportem wersji platformy.
- Modal reimportu jednoznacznie informuje o utracie lokalnej kolejności i usunięć,
  a anulowanie nie wykonuje requestu ani zapisu.
- Po uzgodnieniu, zniknięciu pozycji i purge interfejs nie przedstawia starych
  danych YouTube jako aktualnych.

**Uwaga implementacyjna**: Po zielonej weryfikacji automatycznej zatrzymaj fazę
do ręcznej akceptacji komunikatów i zachowania refresh/reimport.

---

## Phase 4: Handoff do S-05, regresja i wydanie

### Przegląd

Faza zamyka kontrakt równoległych zmian, macierz obu baz danych oraz bramki
repozytorium bez uzależniania ukończenia S-03 od kodu S-05.

### Wymagane zmiany

#### 1. Handoff fingerprintu do S-05

**Pliki**: `app/Actions/Playlists/FingerprintPlaylistContent.php`,
`tests/Feature/PlaylistEditing/PlaylistFingerprintLifecycleTest.php`; planowane
pliki S-05 korzystające z fingerprintu i potwierdzenia są wyłącznie opisanym
kontraktem konsumenta, nie własnością ani bramką S-03

**Cel**: Zagwarantować, że edycja, reimport, refresh i purge unieważniają wynik
przygotowany dla wcześniejszej treści bez sprzężenia S-03 z `ExportReview`.

**Kontrakt**: Test S-03 dowodzi, że reorder, remove, pełny reimport, uzgodnienie
zmienionych danych i purge zmieniają fingerprint, a no-op go zachowuje. Po
rebase S-05 rezygnuje z planowanego duplikatu
`app/Actions/ExportReviews/FingerprintPlaylist.php` i zapisuje wynik neutralnej
klasy wraz ze snapshotem review. Job przed atomową publikacją, widok/retry oraz
confirm przeliczają fingerprint; mismatch zamyka niepotwierdzony review jako
`expired` z bezpieczną przyczyną `source_changed`. Edycja jest dozwolona podczas
review. Potwierdzenie starego review nie usuwa żadnych pozycji. Usunięcia
wykonane przez świeże confirm S-05 ustawiają `bank_content_edited_at` i zachowują
ten sam niezmiennik ciągłych pozycji. Te zmiany należą do implementacji S-05 i
nie blokują ukończenia ani testów S-03.

#### 2. Macierz kolizji i własność plików

**Pliki**: `routes/web.php`, `resources/views/bank/index.blade.php`,
`app/Models/Playlist.php`, `scripts/verify-source-contract`,
`context/foundation/roadmap.md`

**Cel**: Scalić addytywne zmiany bez nadpisania funkcji drugiego wycinka.

**Kontrakt**: Jeden integration owner rereaduje bieżące wersje hot files. S-03
zachowuje osobne trasy, komponenty i testy; S-05 zachowuje własne trasy review,
`ExportReviewPanel` oraz dane karty eksportu. `BankController`, `User` i
`PlaylistItem` pozostają nietknięte przez S-03. Manifest zawiera sumę nowych
ścieżek dokładnie raz. Roadmapa przechodzi tylko do przodu.

#### 3. Pełne bramki jakości

**Pliki**: `tests/Feature/PlaylistEditing/`,
`tests/Unit/Playlists/`, istniejąca konfiguracja CI tylko jeśli nie uruchamia
nowych testów w obecnej macierzy

**Cel**: Zweryfikować bezpieczeństwo danych, współbieżność, dostępność i brak
regresji S-02/S-04 na SQLite oraz PostgreSQL.

**Kontrakt**: Fixture'y obejmują 0, 1 i 20 pozycji, duplikaty, placeholdery,
concurrent edit, reimport, refresh, purge i stary review S-05. Wszystkie requesty
providerów są fake'owane. Nie dodajemy sekretów ani live API do CI.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Cały zestaw S-03 przechodzi: `php artisan test tests/Unit/Playlists tests/Feature/PlaylistEditing`.
- Regresje S-02 i S-04 przechodzą:
  `php artisan test tests/Feature/Playlists tests/Feature/StreamingAccounts`.
- Test cyklu fingerprintu dowodzi, że każda rzeczywista mutacja S-03/S-02 zmienia
  stempel, który S-05 porówna przed publikacją i confirm.
- Pełny zestaw przechodzi: `composer test`.
- Formatowanie przechodzi: `vendor/bin/pint --test`.
- Produkcyjny frontend buduje się: `npm run build`.
- Kontrakt źródła przechodzi: `sh scripts/verify-source-contract --worktree`.
- Audyty zależności przechodzą: `composer audit --locked --no-interaction` oraz
  `npm audit --audit-level=high`.
- CI PostgreSQL przechodzi z `migrate:fresh`, testem wyścigu i bez live requestów.

#### Weryfikacja ręczna

- Użytkownik przechodzi scenariusz 20 pozycji: zmienia kolejność, usuwa kilka,
  zapisuje raz i widzi ten sam wynik po odświeżeniu strony.
- Druga karta i automatyczny refresh pokazują czytelne konflikty bez silent lost
  update; osobny przegląd kontraktu potwierdza, że S-05 wykryje ten sam stempel.
- Responsywny edytor przechodzi klawiaturę, widoczny focus, 200% zoom i czytnik
  ekranu.
- Przegląd bazy, HTML, jobów i logów nie znajduje tokenów, API key, surowych
  payloadów ani nowych danych wrażliwych.
- Addytywna migracja jest gotowa do osobnego, nadzorowanego wydania schematu przez
  publiczną granicę PaaS.

**Uwaga implementacyjna**: Zakończ zmianę dopiero po ręcznej akceptacji UX,
kontraktu S-05 oraz gotowości addytywnej migracji.

## Strategia testowania

### Testy jednostkowe

- Kanoniczny fingerprint: stabilność, wersjonowanie, kolejność, duplikaty,
  placeholdery, nullable pola i wykluczenie row ID/timestampów.
- Walidacja szkicu: unikalność ID, podzbiór bieżących wystąpień, limit 20 i jawne
  potwierdzenie pustego wyniku.
- Uzgodnienie YouTube: retained, missing i source-only occurrence oraz brak
  częściowego wyniku.

### Testy integracyjne

- Owner-scoped GET i każda mutacja Livewire; guest, unverified i cross-user 404.
- Atomowy reorder/remove z dokładną kolejnością, duplikatami i placeholderami.
- Dwie karty, reimport, refresh, purge i zmiana S-05 pomiędzy mount a save.
- Pełny reimport po potwierdzeniu oraz brak provider requestu bez potwierdzenia.
- SQLite i PostgreSQL, w tym przeindeksowanie bez naruszenia unique constraint.

### Kroki testowania ręcznego

1. Otwórz własną playlistę z 20 pozycjami, duplikatem i placeholderem.
2. Przesuń kilka pozycji przyciskami, usuń wybrane i potwierdź, że baza nie
   zmienia się przed użyciem „Zapisz zmiany”.
3. Zapisz raz, odśwież stronę i porównaj dokładną kolejność oraz pochodzenie.
4. Usuń ostatnią pozycję, sprawdź modal i zachowanie anulowania oraz potwierdzenia.
5. Otwórz drugą kartę, zmień playlistę i potwierdź, że starszy szkic nie zapisuje
   się częściowo.
6. Uruchom deterministyczny fake refresh: retained occurrences mają świeże dane,
   lokalny porządek pozostaje, zniknięte stają się placeholderami, a nowe nie są
   dodawane.
7. Spróbuj ogólnego reimportu, następnie potwierdź dedykowany reimport i sprawdź
   pełne zastąpienie oraz wyzerowanie znacznika.
8. Przy aktywnym fake review S-05 zmień playlistę i potwierdź wymaganie nowego
   przeglądu bez wykonania starego confirm.

## Uwagi dotyczące wydajności

Zakres pozostaje ograniczony do 20 wystąpień. Fingerprint, walidacja szkicu i
uzgodnienie działają liniowo, a zapis obejmuje jedną krótką transakcję. Nie jest
potrzebne cache, paginowanie, wirtualizacja ani własny JavaScript. Przyciski
góra/dół są wystarczające przy tym limicie.

## Uwagi dotyczące migracji

Jedyna migracja S-03 dodaje nullable `bank_content_edited_at`; istniejące rekordy
pozostają nietknięte i są interpretowane jako nieedytowane. Rollback usuwa tylko
kolumnę, nie elementy playlist. Wydanie schematu jest osobną nadzorowaną operacją
PaaS przed obrazem aplikacji czytającym nowe pole; plan nie opisuje mechaniki
hosta, transportu, blokad ani rollbacku Managera.

## Referencje

- PRD: `context/foundation/prd.md` — FR-002 i FR-005.
- Roadmapa: `context/foundation/roadmap.md:118` — S-03 oraz równoległość z S-05.
- Ukończony import: `context/archive/2026-09-13-playlist-link-import/plan.md`.
- Gotowy plan równoległy: `context/changes/export-match-review/plan.md` w worktree S-05.
- Model playlisty i kolejność: `app/Models/Playlist.php:13`,
  `database/migrations/2026_09_13_010100_create_playlist_items_table.php:13`.
- Atomowy replacement: `app/Actions/Playlists/ReplaceImportedPlaylist.php:17`.
- Cykl YouTube: `app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php:17`,
  `app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php:53`.
- Wzorzec owner-scoped 404: `app/Http/Controllers/StreamingAccountController.php:31`.
- Granica zewnętrznego PaaS: `AGENTS.md`; plan zachowuje wyłącznie publiczny
  kontrakt konsumenta.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Kontrakt zawartości i atomowa mutacja

#### Automated

- [x] 1.1 Addytywna migracja i cast znacznika przechodzą na SQLite — df39453
- [x] 1.2 Kanoniczny fingerprint przechodzi macierz stabilności i zmian treści — df39453
- [x] 1.3 Atomowa akcja zachowuje dane, duplikaty i ciągłe pozycje — df39453
- [x] 1.4 Konflikty i błędy nie wykonują częściowej mutacji — df39453
- [x] 1.5 Wyścig PostgreSQL nie powoduje silent lost update ani konfliktu pozycji — df39453
- [x] 1.6 Formatowanie i manifest źródła fazy przechodzą — df39453

#### Manual

- [ ] 1.7 Schemat zachowuje minimalną granicę względem S-05
- [ ] 1.8 Fingerprint zostaje zaakceptowany jako wspólny kontrakt S-03/S-05

### Phase 2: Właścicielski edytor playlisty

#### Automated

- [x] 2.1 Trasa i mutacje egzekwują auth, verified i owner-scoped 404 — a4470f8
- [x] 2.2 Livewire obsługuje szkic, granice ruchów, usunięcia i pojedynczy zapis — a4470f8
- [x] 2.3 Pusta lista wymaga jawnego potwierdzenia — a4470f8
- [x] 2.4 Stary szkic kończy się konfliktem bez częściowego zapisu — a4470f8
- [x] 2.5 Bank zachowuje prywatność i minimalne wejście do edytora — a4470f8
- [x] 2.6 Frontend, formatowanie i manifest źródła fazy przechodzą — a4470f8

#### Manual

- [ ] 2.7 Edytor jest czytelny na małym i dużym ekranie
- [ ] 2.8 Klawiatura, focus, 200% zoom i czytnik ekranu przechodzą cały przepływ
- [ ] 2.9 Moment zapisu i konflikt są jednoznaczne dla użytkownika

### Phase 3: Refresh i świadomy reimport

#### Automated

- [x] 3.1 Refresh zachowuje lokalną kolejność i usunięcia po occurrence ID
- [x] 3.2 Zniknięte pozycje stają się placeholderami, a nowe nie są dopisywane
- [x] 3.3 Nietknięty snapshot nadal przechodzi pełny atomowy replacement
- [x] 3.4 Jawny reimport wymaga potwierdzenia, zastępuje snapshot i zeruje znacznik
- [x] 3.5 Cleanup retencji usuwa dane i znacznik w jednej transakcji
- [x] 3.6 Regresje importu i cyklu YouTube przechodzą bez live requestów

#### Manual

- [ ] 3.7 Copy wyjaśnia różnicę między refreshem a pełnym reimportem
- [ ] 3.8 Modal reimportu jasno ostrzega o utracie lokalnych zmian
- [ ] 3.9 UI nie pokazuje wygasłych danych YouTube jako aktualnych

### Phase 4: Handoff do S-05, regresja i wydanie

#### Automated

- [ ] 4.1 Cykl wszystkich mutacji zmienia neutralny fingerprint, a no-op go zachowuje
- [ ] 4.2 Kontrakt fingerprintu jest gotowy do konsumpcji przez S-05 bez duplikatu
- [ ] 4.3 Pełne regresje playlist i integracji streamingowych przechodzą
- [ ] 4.4 Pełny zestaw Composer przechodzi
- [ ] 4.5 Laravel Pint i produkcyjny build przechodzą
- [ ] 4.6 Kontrakt źródła i audyty zależności przechodzą
- [ ] 4.7 Macierz PostgreSQL przechodzi bez live providerów

#### Manual

- [ ] 4.8 Scenariusz 20 pozycji zachowuje dokładny wynik po jednym zapisie
- [ ] 4.9 Druga karta i refresh nie powodują silent lost update, a handoff S-05 jest zaakceptowany
- [ ] 4.10 Końcowy edytor przechodzi pełną weryfikację dostępności
- [ ] 4.11 Dane, HTML, joby i logi nie zawierają sekretów ani surowych payloadów
- [ ] 4.12 Addytywna migracja jest gotowa do nadzorowanego wydania schematu
