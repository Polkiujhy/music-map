# Synchronizacja playlisty źródłowej — plan implementacji

## Przegląd

Implementujemy świadomie włączaną, dwukierunkową synchronizację playlisty w
banku z playlistą źródłową należącą do aktualnie powiązanego konta Spotify lub
YouTube. Użytkownik najpierw ogląda skutki pierwszego uzgodnienia i je
potwierdza, może niezależnie włączyć automat oraz uruchamiać zadanie ręcznie, a
późniejsze konflikty są deterministycznie i bez dodatkowego monitu rozstrzygane
na korzyść źródła.

## Analiza stanu obecnego

`Playlist` zachowuje dostawcę, stabilne ID źródła, deklarowane konto źródłowe,
rewizję dostawcy i `bank_content_edited_at`, lecz nie przechowuje zaakceptowanej
bazy porównawczej, trybu synchronizacji, terminów kontroli ani wyniku ostatniej
próby. `FingerprintPlaylistContent` daje stabilną reprezentację uporządkowanej
zawartości banku, a `UpdateBankPlaylistItems` oznacza wyłącznie rzeczywiste
lokalne zmiany.

Import i odświeżanie nie są jeszcze wiarygodną podstawą własności dla S-08.
Reader Spotify przypisuje jako `sourceAccountId` konto użyte do odczytu zamiast
rzeczywistego `owner.id`, przez co współdzielona playlista może wyglądać jak
własna. Reader YouTube używa publicznego klucza API i nie wiąże importu z
`streaming_account_id`; synchronizacja wymaga nowego odczytu OAuth oraz
porównania kanału właściciela z powiązanym kontem.

Repozytorium ma już wymagane granice techniczne: `WithStreamingAccess` udostępnia
krótkotrwały token wyłącznie w callbacku, `DisableDependentStreamingSynchronizations`
jest wywoływane w transakcji odłączania konta, kolejka i scheduler mają role
produkcyjne, a `AdmitYouTubeWrite` zapewnia globalny limit i trwałą idempotencję
dla typu `source-sync`. Brakuje writerów dostawców i koordynatora kierunku.

Istniejący dzienny `playlists:refresh-youtube-metadata` zachowuje lokalną kolejność
i usunięcia edytowanych playlist. To celowo inna semantyka niż „source wins”,
więc aktywnej synchronizacji nie wolno równolegle obsługiwać tym przepływem.

## Pożądany stan końcowy

Właściciel banku może przygotować synchronizację istniejącego importu. System
wykonuje uwierzytelniony odczyt, potwierdza rzeczywistą własność playlisty przez
to samo powiązane konto, pokazuje podgląd pierwszego kierunku i wymaga
potwierdzenia. Synchronizacja ręczna działa przez kolejkę, a automatyczna jest
osobnym, domyślnie wyłączonym wyborem.

Każda próba porównuje bieżący fingerprint banku i źródła z ostatnią wspólną
bazą. Zmiana wyłącznie banku powoduje push, zmiana źródła powoduje pull, zmiana
obu stron również powoduje pull, a brak zmian kończy się no-op. Playlista
źródłowa mająca ponad 20 elementów lub niedostępna nie modyfikuje żadnej strony
i wstrzymuje automat do działania użytkownika.

Spotify zastępuje listę w jednym żądaniu i zapisuje nowy `snapshot_id`. YouTube
wykonuje minimalny, deterministyczny zestaw operacji elementowych pod jedną
trwałą próbą; dopuszczenie jest pobierane tuż przed pierwszym rzeczywistym
zapisem i używa tego samego operation ID przy każdym ponowieniu. Częściowy wynik
jest uzgadniany przez ponowny odczyt bez ponownego zużywania dopuszczenia.

### Kluczowe odkrycia

- Model ma już pochodzenie, rewizję i znacznik edycji, ale nie stan synchronizacji (`app/Models/Playlist.php:19`).
- Fingerprint ignoruje techniczne ID i rewizje, a zachowuje uporządkowaną treść (`app/Actions/Playlists/FingerprintPlaylistContent.php:9`).
- Lokalny zapis używa blokady, porównania fingerprintu i krótkiej transakcji (`app/Actions/Playlists/UpdateBankPlaylistItems.php:9`).
- Import rozdziela zewnętrzny odczyt od atomowej publikacji do banku (`app/Actions/Playlists/ImportPlaylist.php:42`).
- Odłączenie konta ma gotowy seam do wyłączenia zależnych synchronizacji (`app/Integrations/StreamingAccounts/Actions/DisconnectStreamingAccount.php:19`).
- Typ dopuszczenia `source-sync` już istnieje (`app/Integrations/YouTubeWriteAdmission/YouTubeWriteOperationType.php:9`).
- Spotify ma aktualny endpoint `PUT /playlists/{playlist_id}/items`, limit 100 elementów i rozdzielone zakresy zapisu public/private.
- YouTube nie ma operacji replace; `playlistItems.insert`, `update` i `delete` są osobnymi zapisami po 50 jednostek quota.

## Czego NIE robimy

- Nie synchronizujemy playlist współdzielonych ani należących do innego konta.
- Nie synchronizujemy eksportów i kopii docelowych; wykrywanie ich driftu należy do S-09.
- Nie obsługujemy playlist źródłowych większych niż 20 pozycji ani częściowego pobierania pierwszych 20.
- Nie dodajemy tworzenia nowych utworów, wyszukiwania zamienników ani zmiany metadanych playlisty źródłowej.
- Nie budujemy widocznej dla użytkownika osi historii synchronizacji ani bezterminowego audytu zmian pozycji.
- Nie wykonujemy automatycznego backfillu własności istniejących importów.
- Nie dodajemy automatycznych testów live zależnych od sekretów do zwykłego zestawu CI.
- Nie przenosimy do repozytorium szczegółów działania, konfiguracji hosta ani orkiestracji s-manager; aplikacja udostępnia wyłącznie standardowe role kolejki i schedulera.

## Podejście do implementacji

Dodajemy relację jeden-do-jednego `PlaylistSynchronization` jako trwały automat
stanu oraz ograniczoną tabelę `PlaylistSyncRun` dla wykonywanych i ostatnich
prób. Synchronizacja przechowuje zaakceptowane fingerprinty obu stron,
potwierdzoną rewizję dostawcy, tryb auto, status, terminy i ostatni wynik. Run
przechowuje stabilne operation ID, trigger, kierunek, oczekiwane stany oraz
checkpoint bez poświadczeń, wystarczający do bezpiecznego wznowienia YouTube.

Wspólny koordynator pracuje wyłącznie przez neutralne snapshoty i kontrakty
reader/writer. Po zewnętrznym odczycie wylicza decyzję, następnie pod blokadą
ponownie sprawdza lokalny fingerprint i stan runu. Nie utrzymuje transakcji DB
podczas HTTP. Pull publikuje źródło atomowo w banku z zachowaniem relacji konta;
push wywołuje adapter w callbacku `WithStreamingAccess` i dopiero po
potwierdzonym wyniku przesuwa wspólną bazę.

Spotify korzysta z pełnego replace, bo limit domeny jest mniejszy od limitu API.
YouTube buduje minimalny diff na podstawie stabilnych ID elementów playlisty,
duplikatów i pozycji. Każdy krok zapisuje oczekiwany stan przed i po operacji;
retry uznaje oba za bezpieczne, natomiast inny stan oznacza nową zmianę źródła i
przełącza wynik na source-wins pull zamiast kontynuować push.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Nie wolno trzymać blokady ani transakcji podczas wywołań dostawcy. Podgląd,
potwierdzenie i wykonanie muszą być chronione fingerprintami: jeśli bank lub
źródło zmieniły się po przygotowaniu podglądu, potwierdzenie unieważnia podgląd
i wymaga przygotowania nowego, zamiast wykonywać nieaktualny kierunek.

### Sekwencjonowanie stanu

Dla YouTube `AdmitYouTubeWrite` wywołujemy dopiero po odczycie, kwalifikacji,
wyborze push i potwierdzeniu, że istnieje co najmniej jedna mutacja, ale przed
pierwszym żądaniem zapisującym. Checkpoint runu i stabilne operation ID powstają
wcześniej; po niejednoznacznym błędzie sieci retry najpierw odczytuje źródło i
rozpoznaje stan przed/po kroku. Pull, no-op i odmowa nie zużywają dopuszczenia.

### Debugowanie i obserwowalność

Logi używają ID playlisty, ID runu, triggera, kierunku, dostawcy, wyniku i
sanityzowanego kodu błędu. Nie zawierają tokenów, URL-i źródłowych, payloadów
dostawcy ani zewnętrznego ID konta. Bieżący status i ostatni wynik pozostają w
bazie, a zakończone runy są usuwane według ograniczonej retencji.

## Phase 1: Model synchronizacji i baza porównawcza

### Przegląd

Wprowadzamy jawny automat stanu, trwałe runy i czyste reguły wyboru kierunku,
bez wykonywania HTTP i bez uruchamiania funkcji dla użytkownika.

### Wymagane zmiany

#### 1. Schemat synchronizacji i prób

**Pliki**: `database/migrations/<timestamp>_create_playlist_synchronizations_table.php`, `database/migrations/<timestamp>_create_playlist_sync_runs_table.php`

**Cel**: Zapisać zaakceptowaną bazę porównawczą, tryb auto, cykl kontroli, wynik oraz trwały kontekst potrzebny do wznowienia zapisu.

**Kontrakt**: Synchronizacja ma unikalny `playlist_id`, nullable `streaming_account_id`, stan `pending-confirmation|enabled|attention|disabled`, `automatic_enabled`, bazowe fingerprinty i rewizję, `last_checked_at`, `last_succeeded_at`, `next_check_at`, ostatni outcome/failure oraz optimistic timestamps. Run ma UUID/ULID `operation_id` o długości zgodnej z admission, trigger, kierunek, stan, snapshoty wejściowe, desired fingerprint i nullable checkpoint JSON bez sekretów. Indeksy obejmują due auto-sync oraz aktywny run; usunięcie playlisty kaskaduje, a usunięcie konta zeruje FK przed transakcyjnym wyłączeniem.

#### 2. Modele, enumy i fabryki

**Pliki**: `app/Models/PlaylistSynchronization.php`, `app/Models/PlaylistSyncRun.php`, `app/Models/Playlist.php`, `app/Models/StreamingAccount.php`, `app/Enums/PlaylistSyncStatus.php`, `app/Enums/PlaylistSyncTrigger.php`, `app/Enums/PlaylistSyncDirection.php`, `app/Enums/PlaylistSyncOutcome.php`, `database/factories/PlaylistSynchronizationFactory.php`, `database/factories/PlaylistSyncRunFactory.php`

**Cel**: Udostępnić typowany kontrakt domenowy i relacje bez rozlewania stringów statusów po aplikacji.

**Kontrakt**: Casty zachowują immutable datetimes, bool i enumy; relacje są jednoznaczne; fabryki używają bezpiecznych canary values i domyślnie nie tworzą aktywnej automatyzacji.

#### 3. Fingerprint źródła i wybór kierunku

**Pliki**: `app/Actions/PlaylistSync/FingerprintSourcePlaylist.php`, `app/Actions/PlaylistSync/DeterminePlaylistSyncDirection.php`, `app/Integrations/PlaylistSync/Data/SourcePlaylistSnapshot.php`, `tests/Unit/PlaylistSync/DeterminePlaylistSyncDirectionTest.php`, `tests/Unit/PlaylistSync/FingerprintSourcePlaylistTest.php`

**Cel**: Oddzielić wykrywanie zmian od rewizji specyficznych dla dostawcy i zdefiniować jedną testowalną macierz konfliktu.

**Kontrakt**: Fingerprint v1 obejmuje uporządkowane, znormalizowane identyfikatory pozycji z zachowaniem duplikatów. Decyzja to: source changed → pull; tylko bank changed → push; żadna strona → no-op. Brak bazy nie uruchamia zapisu i jest obsługiwany wyłącznie przez aktywację.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Migracje stosują się i cofają na SQLite, zachowując FK, uniqueness i indeks due.
- Testy modeli potwierdzają relacje, casty, enumy, brak sekretów w checkpointach i bezpieczne wartości fabryk.
- Testy jednostkowe pokrywają pełną macierz no-op/push/pull/conflict, duplikaty, kolejność i zmianę wersji fingerprintu.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą.

#### Weryfikacja ręczna

- Inspekcja schematu potwierdza, że stan wystarcza do wznowienia, ale nie duplikuje tokenów ani szczegółów s-manager.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się i uzyskaj potwierdzenie ręczne przed Fazą 2.

---

## Phase 2: Uwierzytelniony odczyt i aktywacja

### Przegląd

Naprawiamy kontrakt własności, dodajemy prywatny odczyt przez OAuth i budujemy
bezpieczny przepływ przygotuj → obejrzyj → potwierdź dla nowych oraz istniejących
importów.

### Wymagane zmiany

#### 1. Własność i zakresy dostawców

**Pliki**: `app/Enums/StreamingProvider.php`, `app/Integrations/StreamingAccounts/SpotifyOAuthGateway.php`, `app/Integrations/PlaylistImport/Providers/SpotifyPlaylistReader.php`, `app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php`, `tests/Unit/Integrations/StreamingAccounts/SpotifyOAuthGatewayTest.php`, `tests/Unit/Integrations/PlaylistImport/SpotifyPlaylistReaderTest.php`

**Cel**: Zapisać prawdziwego właściciela przy imporcie Spotify i uzyskać zgodę potrzebną do publicznych oraz prywatnych zapisów.

**Kontrakt**: `source_account_id` Spotify pochodzi z `owner.id`, nie z konta użytego do odczytu. Nowy grant żąda `playlist-modify-public` i `playlist-modify-private`; konto bez nowych zakresów ma `reconnect_required` przed aktywacją. Publiczny import YouTube pozostaje bez tokena i nie staje się automatycznie uprawniony do synchronizacji.

#### 2. Neutralny uwierzytelniony reader synchronizacji

**Pliki**: `app/Integrations/PlaylistSync/Contracts/SourcePlaylistReader.php`, `app/Integrations/PlaylistSync/SourcePlaylistReaderRegistry.php`, `app/Integrations/PlaylistSync/Providers/SpotifySourcePlaylistReader.php`, `app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistReader.php`, `app/Integrations/PlaylistSync/SourceSyncFailure.php`, `app/Providers/PlaylistSyncServiceProvider.php`

**Cel**: Odczytywać pełny stan źródła, rewizję i rzeczywistego właściciela w granicy krótkotrwałego dostępu OAuth.

**Kontrakt**: Reader zwraca maksymalnie 20 elementów albo jawny wynik `over-limit`, zachowuje provider item ID wymagane przez writer YouTube i mapuje 401/403/404/429/5xx na stabilne kody. Własność kwalifikuje się wyłącznie przy dokładnym dopasowaniu owner/channel ID do `provider_account_id` powiązanego konta.

#### 3. Przygotowanie i potwierdzenie aktywacji

**Pliki**: `app/Actions/PlaylistSync/PreparePlaylistSynchronization.php`, `app/Actions/PlaylistSync/ConfirmPlaylistSynchronization.php`, `app/Http/Controllers/PlaylistSynchronizationController.php`, `app/Http/Requests/ConfirmPlaylistSynchronizationRequest.php`, `routes/web.php`

**Cel**: Zweryfikować istniejący import dopiero na żądanie i wymagać świadomej akceptacji pierwszego uzgodnienia.

**Kontrakt**: Prepare autoryzuje właściciela banku, wybiera powiązane konto tego samego dostawcy, wykonuje OAuth read i zapisuje pending preview z fingerprintami, rewizją, kierunkiem i licznością zmian. Confirm przyjmuje opaque preview token, ponownie sprawdza lokalny fingerprint oraz aktualność źródła i zleca activation run; stale preview nie zapisuje i wymaga odświeżenia. Potwierdzenie nie włącza auto.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy readerów z `Http::preventStrayRequests()` potwierdzają endpointy, paginację, owner ID, prywatny dostęp, duplikaty, provider item ID i mapowanie błędów.
- Testy OAuth potwierdzają oba zakresy modyfikacji Spotify oraz wymóg reconnect dla historycznego grantu.
- Testy feature potwierdzają autoryzację, weryfikację własności istniejących importów, odmowę collaborator/mismatched channel, limit ponad 20 i unieważnienie nieaktualnego podglądu.
- Test potwierdza, że samo przygotowanie i potwierdzenie przed wykonaniem nie wywołuje writerów ani YouTube admission.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą.

#### Weryfikacja ręczna

- Podgląd pierwszej synchronizacji jasno pokazuje kierunek i skutek przed potwierdzeniem.
- Istniejąca publiczna i prywatna playlista Spotify wymaga właściwego reconnect i kwalifikuje się dopiero po potwierdzeniu właściciela.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się i uzyskaj potwierdzenie ręczne przed Fazą 3.

---

## Phase 3: Koordynator i adaptery zapisu

### Przegląd

Implementujemy spójny przepływ no-op/pull/push oraz różne strategie zapisu:
pojedynczy replace Spotify i minimalny, checkpointowany diff YouTube.

### Wymagane zmiany

#### 1. Koordynator runu i atomowy pull

**Pliki**: `app/Actions/PlaylistSync/RunPlaylistSynchronization.php`, `app/Actions/PlaylistSync/ApplySourcePlaylistToBank.php`, `app/Actions/PlaylistSync/CompletePlaylistSyncRun.php`, `app/Actions/PlaylistSync/FailPlaylistSyncRun.php`

**Cel**: W jednym miejscu egzekwować source-wins, rewalidację po HTTP i przesuwanie bazy dopiero po potwierdzonym sukcesie.

**Kontrakt**: Run ładuje wyłącznie ID, uzyskuje token w `WithStreamingAccess`, odczytuje źródło poza transakcją, a potem pod blokadą sprawdza aktualny bank i aktywny run. Pull atomowo zastępuje elementy, zachowuje `streaming_account_id`, aktualizuje rewizję i zeruje lokalny marker; no-op tylko odświeża bazę/czasy. `over-limit` i niedostępne źródło nie modyfikują żadnej listy i przechodzą do `attention`.

#### 2. Writer Spotify

**Pliki**: `app/Integrations/PlaylistSync/Contracts/SourcePlaylistWriter.php`, `app/Integrations/PlaylistSync/SourcePlaylistWriterRegistry.php`, `app/Integrations/PlaylistSync/Providers/SpotifySourcePlaylistWriter.php`, `tests/Unit/Integrations/PlaylistSync/SpotifySourcePlaylistWriterTest.php`

**Cel**: Zastępować całą zawartość własnej playlisty Spotify w jednym żądaniu i zwracać potwierdzoną rewizję.

**Kontrakt**: Writer używa wyłącznie zapisanego `source_playlist_id`, wysyła uporządkowane Spotify URI do aktualnego endpointu items, respektuje widoczność i wymagany scope, zapisuje zwrócony `snapshot_id`, a następnie wykonuje kontrolny odczyt. Nie tworzy playlisty i nie używa URL-a jako tożsamości.

#### 3. Planner i writer YouTube

**Pliki**: `app/Integrations/PlaylistSync/YouTube/PlanYouTubePlaylistMutations.php`, `app/Integrations/PlaylistSync/YouTube/YouTubePlaylistMutation.php`, `app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistWriter.php`, `tests/Unit/Integrations/PlaylistSync/PlanYouTubePlaylistMutationsTest.php`, `tests/Unit/Integrations/PlaylistSync/YouTubeSourcePlaylistWriterTest.php`

**Cel**: Osiągnąć dokładną kolejność do 20 filmów możliwie małą liczbą zapisów i bezpiecznie wznowić operację po niejednoznacznym wyniku.

**Kontrakt**: Planner uwzględnia duplikaty przez playlistItem ID i generuje deterministyczne delete/insert/update-position. Każdy checkpoint przechowuje oczekiwany fingerprint przed i po kroku. Retry najpierw czyta źródło: stan before ponawia krok, after przechodzi dalej, desired kończy sukcesem, a każdy inny stan oznacza zewnętrzny drift i kończy push przez source-wins pull. Writer nie zapisuje tokena ani pełnych odpowiedzi HTTP.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy koordynatora pokrywają cztery kierunki, atomowy pull, zachowanie konta, markerów i bazy oraz brak transakcji podczas HTTP.
- Testy Spotify potwierdzają pojedynczy replace dla 0 i 20 elementów, kolejność, duplikaty, rewizję, post-read oraz stabilne błędy 401/403/404/429/5xx.
- Testy YouTube potwierdzają minimalny i deterministyczny diff, 0 i 20 elementów, duplikaty, usunięcia, inserty, przesunięcia oraz ponowienie ze stanów before/after.
- Testy celowo łamią wynik po każdym kroku YouTube i dowodzą, że retry kończy w dokładnym desired state bez podwójnych mutacji.
- Testy współbieżności PostgreSQL potwierdzają source-wins, rewalidację banku i pojedynczy aktywny run.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą.

#### Weryfikacja ręczna

- Przegląd requestów potwierdza, że writerzy dotykają wyłącznie źródła po zapisanym ID i nie logują danych poufnych.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się i uzyskaj potwierdzenie ręczne przed Fazą 4.

---

## Phase 4: Wykonywanie i automatyzacja

### Przegląd

Podłączamy koordynator do unikalnych zadań kolejki, dopuszczenia zapisów YouTube,
rozłożonych terminów, zdarzenia logowania i cyklu odłączenia konta.

### Wymagane zmiany

#### 1. Unikalne zadanie i polityka retry

**Pliki**: `app/Jobs/RunPlaylistSynchronization.php`, `app/Actions/PlaylistSync/DispatchPlaylistSynchronization.php`, `config/playlist-sync.php`

**Cel**: Uruchamiać ręczne, automatyczne, loginowe i aktywacyjne próby tym samym bezpiecznym mechanizmem.

**Kontrakt**: Job serializuje wyłącznie ID runu, jest unikalny per synchronizacja, używa `WithoutOverlapping`, ograniczonego backoff i istniejącego budżetu timeout workera. Odpowiedzi przejściowe są ponawiane, błędy auth/ownership/over-limit przechodzą do `attention`, a nieudany terminalnie run pozostawia jednoznaczny kod i nie przesuwa bazy.

#### 2. Dopuszczenie zapisu YouTube

**Pliki**: `app/Actions/PlaylistSync/RunPlaylistSynchronization.php`, `app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistWriter.php`, `tests/Feature/PlaylistSync/YouTubeSourceSyncAdmissionTest.php`, `tests/Feature/PlaylistSync/YouTubeSourceSyncAdmissionPostgresTest.php`

**Cel**: Współdzielić globalny limit z eksportami i nie naliczać odczytów, pull, no-op ani retry jako nowych operacji.

**Kontrakt**: Admission typu `SourceSync` otrzymuje `PlaylistSyncRun.operation_id` bez aktywnej transakcji, po potwierdzeniu niepustego planu mutacji i przed pierwszym zapisem. Odmowa pozostawia run retryable/attention bez mutacji; wszystkie kolejne próby używają tego samego ID bezterminowo.

#### 3. Terminy, scheduler i logowanie

**Pliki**: `app/Console/Commands/DispatchDuePlaylistSynchronizations.php`, `app/Listeners/DispatchDuePlaylistSynchronizationsAfterLogin.php`, `app/Providers/AppServiceProvider.php`, `routes/console.php`, `config/playlist-sync.php`

**Cel**: Zapewnić wykrycie automatycznych zmian najpóźniej w cztery godziny i niedrogi check po logowaniu.

**Kontrakt**: Scheduler często wybiera małymi partiami rekordy `enabled`, `automatic_enabled` i `next_check_at <= now`, atomowo przesuwa termin z deterministycznym jitterem mieszczącym się w czterech godzinach i dispatchuje job. Listener `Login` dispatchuje tylko synchronizacje, których ostatnia kontrola była co najmniej 15 minut temu. Oba wejścia są idempotentne i nie wykonują HTTP synchronicznie.

#### 4. Odłączenie i istniejący refresh YouTube

**Pliki**: `app/Actions/PlaylistSync/DisableAccountPlaylistSynchronizations.php`, `app/Providers/StreamingAccountsServiceProvider.php`, `app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php`, `app/Jobs/RefreshYouTubePlaylistMetadata.php`

**Cel**: Wyłączyć zależne synchronizacje w tej samej transakcji co unlink i zapobiec konkurencji starego refreshu z nową semantyką.

**Kontrakt**: Implementacja seamu zbiorczo ustawia `disabled`, wyłącza auto i anuluje dispatchowalne runy bez HTTP; ponowne połączenie nie wznawia ich automatycznie. Refresh metadanych pomija playlisty mające pending/enabled/attention sync, które są własnością koordynatora S-08.

#### 5. Ograniczona retencja i logowanie

**Pliki**: `app/Console/Commands/PrunePlaylistSyncRuns.php`, `routes/console.php`, `config/playlist-sync.php`

**Cel**: Zachować wyłącznie dane potrzebne do aktywnego retry i krótkiej diagnostyki.

**Kontrakt**: Aktywne i niejednoznaczne runy nie są usuwane; zakończone runy starsze od konfigurowalnego, bezpiecznego okresu są porcjami usuwane. Logi stosują wyłącznie sanityzowany kontrakt opisany powyżej.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy jobów potwierdzają unikalność, overlap lock, klasyfikację retry/terminal oraz brak tokenów w serializacji.
- Testy admission na SQLite i PostgreSQL potwierdzają zero rezerwacji dla pull/no-op/refusal, jedną dla rzeczywistego push i ponowne użycie ID po częściowym błędzie.
- Testy czasu potwierdzają termin nieprzekraczający 4 godzin, próg loginowy 15 minut, batchowanie i brak podwójnego dispatchu.
- Test unlink potwierdza wyłączenie przed usunięciem konta, brak HTTP i brak automatycznego wznowienia po reconnect.
- Test lifecycle potwierdza, że stary refresh YouTube pomija synchronizowane playlisty.
- Test pruning zachowuje aktywne/niejednoznaczne runy i usuwa wyłącznie kwalifikujące się zakończone rekordy.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą.

#### Weryfikacja ręczna

- Uruchomienie istniejących ról queue i scheduler przetwarza due sync bez dodatkowego publicznego kontraktu s-manager.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się i uzyskaj potwierdzenie ręczne przed Fazą 5.

---

## Phase 5: Interfejs i zabezpieczenia operacyjne

### Przegląd

Udostępniamy pełny przepływ właścicielowi playlisty: kwalifikację, podgląd,
potwierdzenie, ręczny start, osobny tryb auto, bieżący wynik i odzyskiwanie po
stanach wymagających uwagi.

### Wymagane zmiany

#### 1. Panel synchronizacji playlisty

**Pliki**: `resources/views/playlists/edit.blade.php`, `resources/views/playlists/_synchronization.blade.php`, `app/Http/Controllers/PlaylistSynchronizationController.php`, `routes/web.php`

**Cel**: Umieścić synchronizację obok istniejącego edytora, bez tworzenia osobnego obszaru produktu.

**Kontrakt**: Panel pokazuje kwalifikację, dostawcę, wyłączony/pending/running/success/attention, czas ostatniego sprawdzenia i ostatni wynik. Nie pokazuje historii konfliktów. Kontrolki używają dostępnych etykiet i są autoryzowane policy/ownership; ręczny start tylko dispatchuje i zwraca szybko.

#### 2. Aktywacja, auto i odzyskiwanie

**Pliki**: `app/Http/Controllers/PlaylistSynchronizationController.php`, `app/Http/Requests/UpdatePlaylistSynchronizationRequest.php`, `resources/views/playlists/_synchronization.blade.php`

**Cel**: Zrealizować zaakceptowany UX oraz bezpieczne wyjście ze stanów `attention` i `disabled`.

**Kontrakt**: Pierwszy preview pokazuje, która strona się zmieni i liczbę pozycji; confirm jest jawny. Auto ma osobny przełącznik i nie włącza się z confirm. Over-limit i missing/inaccessible zachowują bank, wyłączają dispatch auto i pokazują działanie naprawcze. Po unlink/reconnect wymagane jest ponowne prepare/confirm. Konflikt w aktywnym sync cicho wykonuje pull i kończy zwykłym statusem sukcesu.

#### 3. Dostępność i bezpieczne komunikaty

**Pliki**: `resources/views/playlists/_synchronization.blade.php`, `resources/css/app.css`, `tests/Feature/PlaylistSync/PlaylistSynchronizationUiTest.php`

**Cel**: Zapewnić czytelny stan bez ujawniania odpowiedzi dostawcy lub technicznych identyfikatorów.

**Kontrakt**: Komunikaty mają stabilne, użytkowe tłumaczenie kodów failure; stan oczekujący nie udaje sukcesu; formularze zachowują CSRF, focus i role/labels zgodne z istniejącym UI. Nie dodajemy `data-testid`, jeśli semantyczny locator jest jednoznaczny.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy feature pokrywają niekwalifikujące się importy, preview/confirm, stale preview, ręczny dispatch, niezależny toggle auto i wszystkie stany uwagi.
- Testy autoryzacji dowodzą, że obcy użytkownik nie odczyta statusu ani nie uruchomi i nie zmieni synchronizacji.
- Testy UI potwierdzają semantyczne labels/roles, komunikaty bez identyfikatorów dostawcy i brak synchronicznego HTTP przy ręcznym starcie.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą.

#### Weryfikacja ręczna

- Właściciel przechodzi pełny preview → confirm → manual sync oraz osobno włącza i wyłącza auto na desktopie i mobile.
- Over-limit, utrata dostępu i reconnect mają zrozumiałe komunikaty i nie zmieniają banku bez potwierdzenia.
- Konflikt po aktywacji przywraca źródło bez dodatkowego monitu, zgodnie z wybraną semantyką.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się i uzyskaj potwierdzenie ręczne przed Fazą 6.

---

## Phase 6: Weryfikacja przekrojowa

### Przegląd

Domykamy scenariusze wyścigów i awarii, dokumentujemy publiczną konfigurację
aplikacji oraz przeprowadzamy kontrolowane smoke testy prawdziwych kont obu
dostawców.

### Wymagane zmiany

#### 1. Macierz integracyjna i celowe przerwania

**Pliki**: `tests/Feature/PlaylistSync/PlaylistSynchronizationTest.php`, `tests/Feature/PlaylistSync/PlaylistSynchronizationPostgresTest.php`, `tests/Feature/PlaylistSync/PlaylistSynchronizationFailureTest.php`

**Cel**: Udowodnić zachowanie całego przepływu na granicach, których nie pokrywają izolowane adaptery.

**Kontrakt**: Macierz obejmuje 0/20/>20, kolejność, duplikaty, placeholdery/niedostępne elementy, zmianę banku podczas read, zmianę źródła podczas push, wygaśnięcie tokena, 429/5xx, odmowę admission, częściowy YouTube write i ponowne uruchomienie procesu. Każdy test ma własne dane i pełne porównanie stanu przed/po odmowie.

#### 2. Konfiguracja i dokumentacja operatora aplikacji

**Pliki**: `.env.example`, `config/playlist-sync.php`, `README.md`

**Cel**: Udokumentować wyłącznie publiczny kontrakt konsumenta potrzebny do terminów, batchy i retencji.

**Kontrakt**: Bezpieczne placeholdery opisują interwał maksymalnie 4h, próg login 15m, batch i retencję; README wskazuje standardowe `queue`/`scheduler` oraz wymagane zakresy OAuth. Nie zawiera sekretów ani szczegółów implementacji Managera.

#### 3. Ręczne smoke testy dostawców

**Pliki**: `context/changes/source-playlist-sync/smoke-test.md`

**Cel**: Zweryfikować aktualne OAuth i rzeczywiste mutacje poza deterministycznym CI.

**Kontrakt**: Checklista używa dedykowanych playlist testowych do 20 pozycji i unikalnych nazw, obejmuje prywatne/publiczne Spotify, prywatne YouTube, pull, push, source-wins, częściowy retry możliwy do bezpiecznej symulacji oraz sprzątanie. Sekrety i tokeny nigdy nie trafiają do pliku ani logów.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełna macierz SQLite przechodzi z `Http::preventStrayRequests()` i dokładną liczbą żądań.
- PostgreSQL potwierdza blokady, uniqueness aktywnego runu, idempotentne dispatch/admission i poprawny cleanup testów.
- Celowe przerwanie każdego etapu zapisu YouTube kończy się desired state albo bezpiecznym source-wins pull, nigdy podwójnym admission.
- `.env.example`, konfiguracja i README zawierają wyłącznie bezpieczny publiczny kontrakt aplikacji.
- `composer test`, `vendor/bin/pint --test` i `npm run build` przechodzą na czystym drzewie zależności.

#### Weryfikacja ręczna

- Kontrolowany smoke test Spotify przechodzi dla własnej playlisty publicznej i prywatnej, w tym reconnect do nowych zakresów.
- Kontrolowany smoke test YouTube przechodzi dla pull, rzeczywistego push i ponowienia pod tym samym operation ID.
- Po smoke testach playlisty testowe zostają przywrócone lub usunięte, a w artefaktach nie ma sekretów.

**Uwaga implementacyjna**: Zmiana jest gotowa do przeglądu dopiero po automatycznej macierzy i ręcznych smoke testach.

## Strategia testowania

### Testy jednostkowe

- Fingerprint źródła i pełna macierz wyboru kierunku.
- Deterministyczny planner minimalnych mutacji YouTube, szczególnie duplikaty i przesunięcia.
- Mapowanie odpowiedzi oraz failure codes obu dostawców.
- Enumy, casty, przejścia automatu stanu i reguły retencji.

### Testy integracyjne

- Aktywacja istniejącego importu z uwierzytelnionym potwierdzeniem właściciela.
- Pull/no-op/push/conflict dla obu dostawców z pełnym stanem banku przed i po.
- Wyścigi bank/source/run na PostgreSQL bez blokady utrzymywanej podczas HTTP.
- Każdy punkt częściowego zapisu YouTube, admission i bezpieczne ponowienie po restarcie procesu.
- Scheduler, logowanie, ręczny dispatch, unlink i wykluczenie starego refreshu YouTube.
- Autoryzacja i bezpieczna prezentacja stanów w UI.

### Kroki testowania ręcznego

1. Na kontrolowanym koncie Spotify zaimportuj własną playlistę prywatną, przygotuj podgląd, potwierdź, wykonaj pull i push.
2. Powtórz dla własnej publicznej playlisty po ponownym przyznaniu rozszerzonych zakresów.
3. Na kontrolowanym koncie YouTube wykonaj ten sam przepływ i potwierdź, że rzeczywisty push zużył jedno logiczne dopuszczenie.
4. Zmień obie strony przed kolejną próbą i potwierdź cichy source-wins pull.
5. Ustaw źródło powyżej 20 pozycji i odbierz dostęp; potwierdź zachowanie banku oraz stan wymagający uwagi.
6. Odłącz i połącz ponownie konto; potwierdź brak automatycznego wznowienia oraz nowy preview/confirm.
7. Włącz auto i zweryfikuj kontrolę przez scheduler oraz próg 15 minut po logowaniu.

## Uwagi dotyczące wydajności

Zapytania schedulera muszą korzystać z indeksu `status + automatic_enabled +
next_check_at`, pobierać małe partie i atomowo claimować termin przed dispatch.
Żadne wejście HTTP nie czeka na API dostawcy. Readery kończą po stwierdzeniu
21. pozycji, bo dalsze pobieranie nie zmieni wyniku `over-limit`.

Spotify wykonuje jeden replace i jeden kontrolny odczyt. YouTube preferuje
minimalny diff, ponieważ każda mutacja kosztuje quota; dodatkowy tani odczyt jest
akceptowany dla bezpieczeństwa checkpointu. Retencja runów jest porcjowana i nie
usuwa aktywnych lub niejednoznacznych operacji.

## Uwagi dotyczące migracji

Migracje wyłącznie dodają nowe tabele i relacje; istniejące playlisty pozostają
niezsynchronizowane. Nie wykonujemy backfillu przez API. Pierwsze prepare na
żądanie weryfikuje właściciela i tworzy bazę dopiero po confirm. Historyczne
konta Spotify bez `playlist-modify-public` są oznaczane do reconnect dopiero,
gdy dana aktywacja potrzebuje rozszerzonego zakresu.

Rollback schematu usuwa wyłącznie nowe rekordy synchronizacji i nie zmienia
banku ani playlist dostawców. Rollback aplikacji po rozpoczętym zewnętrznym
zapisie nie próbuje odwracać zmian; aktywny checkpoint musi pozostać dostępny do
uzgodnienia przed usunięciem tabel, dlatego wdrożenie wycofujące najpierw
zatrzymuje dispatch i opróżnia lub oznacza aktywne runy.

## Referencje

- PRD: `context/foundation/prd.md` — US-02, FR-005, NFR-004, NFR-006.
- Roadmapa: `context/foundation/roadmap.md` — S-08.
- Zasady: `context/foundation/lessons.md`.
- Dostęp OAuth: `app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php:20`.
- Edycja i fingerprint: `app/Actions/Playlists/UpdateBankPlaylistItems.php:9`, `app/Actions/Playlists/FingerprintPlaylistContent.php:9`.
- Aktualny refresh YouTube: `app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php:37`, `routes/console.php:11`.
- YouTube write admission: `app/Integrations/YouTubeWriteAdmission/Contracts/AdmitYouTubeWrite.php`, `app/Integrations/YouTubeWriteAdmission/YouTubeWriteOperationType.php:9`.
- Spotify Get Playlist: https://developer.spotify.com/documentation/web-api/reference/get-playlist
- Spotify Update Playlist Items: https://developer.spotify.com/documentation/web-api/reference/reorder-or-replace-playlists-items
- Spotify scopes i snapshots: https://developer.spotify.com/documentation/web-api/concepts/scopes, https://developer.spotify.com/documentation/web-api/concepts/playlists
- YouTube playlistItems: https://developers.google.com/youtube/v3/docs/playlistItems
- YouTube quota: https://developers.google.com/youtube/v3/determine_quota_cost

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Model synchronizacji i baza porównawcza

#### Automated

- [x] 1.1 Migracje synchronizacji stosują się i cofają z poprawnymi ograniczeniami
- [x] 1.2 Modele, enumy, relacje i fabryki spełniają kontrakt trwałego stanu
- [x] 1.3 Fingerprint i pełna macierz kierunków przechodzą w testach jednostkowych
- [x] 1.4 Pełne composer test, Pint i build przechodzą

#### Manual

- [ ] 1.5 Schemat nie przechowuje sekretów ani szczegółów s-manager

### Phase 2: Uwierzytelniony odczyt i aktywacja

#### Automated

- [ ] 2.1 Readery OAuth potwierdzają właściciela, limit i stabilne błędy
- [ ] 2.2 Spotify wymaga zakresów zapisu publicznego i prywatnego oraz reconnect
- [ ] 2.3 Prepare i confirm chronią autoryzację, własność i aktualność preview
- [ ] 2.4 Aktywacja przed wykonaniem nie zapisuje do dostawcy ani admission
- [ ] 2.5 Pełne composer test, Pint i build przechodzą

#### Manual

- [ ] 2.6 Podgląd pierwszej synchronizacji jasno pokazuje kierunek i skutek
- [ ] 2.7 Istniejące publiczne i prywatne Spotify przechodzą właściwą kwalifikację

### Phase 3: Koordynator i adaptery zapisu

#### Automated

- [ ] 3.1 Koordynator realizuje no-op, pull, push i source-wins bez HTTP w transakcji
- [ ] 3.2 Spotify wykonuje pojedynczy replace i kontrolny odczyt
- [ ] 3.3 YouTube generuje minimalny deterministyczny diff dla przypadków granicznych
- [ ] 3.4 Celowe przerwania YouTube wznawiają się bez podwójnych mutacji
- [ ] 3.5 PostgreSQL potwierdza rewalidację i pojedynczy aktywny run
- [ ] 3.6 Pełne composer test, Pint i build przechodzą

#### Manual

- [ ] 3.7 Writerzy używają zapisanych ID i nie logują danych poufnych

### Phase 4: Wykonywanie i automatyzacja

#### Automated

- [ ] 4.1 Joby są unikalne, odporne na overlap i mają właściwą klasyfikację retry
- [ ] 4.2 YouTube admission nalicza wyłącznie pierwszą rzeczywistą operację push
- [ ] 4.3 Scheduler i login spełniają progi czterech godzin i piętnastu minut
- [ ] 4.4 Unlink wyłącza sync, a historyczny refresh YouTube go pomija
- [ ] 4.5 Retencja zachowuje aktywne runy i usuwa wyłącznie zakończone
- [ ] 4.6 Pełne composer test, Pint i build przechodzą

#### Manual

- [ ] 4.7 Standardowe role queue i scheduler obsługują sync bez rozszerzenia kontraktu PaaS

### Phase 5: Interfejs i zabezpieczenia operacyjne

#### Automated

- [ ] 5.1 UI pokrywa preview, confirm, manual dispatch, auto toggle i stany uwagi
- [ ] 5.2 Autoryzacja izoluje status i operacje synchronizacji między użytkownikami
- [ ] 5.3 Semantyczne kontrolki i komunikaty nie ujawniają technicznych danych
- [ ] 5.4 Pełne composer test, Pint i build przechodzą

#### Manual

- [ ] 5.5 Pełny przepływ działa na desktopie i mobile
- [ ] 5.6 Over-limit, utrata dostępu i reconnect zachowują bank i jasno prowadzą użytkownika
- [ ] 5.7 Konflikt aktywnego sync cicho przywraca stan źródła

### Phase 6: Weryfikacja przekrojowa

#### Automated

- [ ] 6.1 Macierz SQLite przechodzi bez stray requests i z dokładną liczbą wywołań
- [ ] 6.2 PostgreSQL potwierdza blokady, idempotencję oraz cleanup
- [ ] 6.3 Każde przerwanie YouTube kończy się desired state lub source-wins bez drugiego admission
- [ ] 6.4 Konfiguracja i dokumentacja zawierają tylko publiczny kontrakt aplikacji
- [ ] 6.5 Pełne composer test, Pint i build przechodzą na czystym środowisku

#### Manual

- [ ] 6.6 Smoke test Spotify przechodzi dla własnej playlisty publicznej i prywatnej
- [ ] 6.7 Smoke test YouTube przechodzi dla pull, push i ponowienia operation ID
- [ ] 6.8 Dane testowe są posprzątane, a artefakty nie zawierają sekretów
