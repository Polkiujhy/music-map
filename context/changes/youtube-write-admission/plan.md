# Wspólne dopuszczenie zapisów YouTube — plan implementacji

## Przegląd

Zmiana implementuje fundament F-02: jedną trwałą i atomową bramkę, przez którą
przyszłe eksporty oraz synchronizacje muszą przejść bezpośrednio przed pierwszym
zapisem do YouTube. Bramka dopuszcza globalnie najwyżej skonfigurowaną liczbę
nowych logicznych operacji w dniu kwoty YouTube, domyślnie pięć, a ponowienia
tej samej operacji bezterminowo wykorzystują pierwotną rezerwację.

F-02 kończy się kontraktem aplikacyjnym i dowodem współbieżności. Nie wykonuje
wywołań YouTube ani nie uruchamia eksportu lub synchronizacji; konsumenci S-06,
S-07, S-08 i S-09 dołączą bramkę bez zmiany jej semantyki.

## Analiza stanu obecnego

PRD i roadmapa wymagają wspólnego globalnego ograniczenia zapisów YouTube, ale
baza kodu nie ma modelu logicznej operacji zapisu, trwałej rezerwacji ani
jednego punktu dopuszczenia. Potwierdzenie `ExportReview` zamraża dokładny
manifest dla przyszłego eksportu, lecz celowo nie zapisuje jeszcze niczego u
providera.

Istniejący `YouTubeSearchBudget` chroni osobny budżet odczytów `search.list`.
Jest oparty na cache, rozlicza unikalne braki wyników wyszukiwania i wygasa na
koniec dnia aplikacji. Cache jest zasobem disposable, dlatego nie może być
księgą bezterminowej idempotencji ani dowodem atomowego limitu zapisów między
workerami.

Repozytorium stosuje transakcje z `lockForUpdate()`, unikalne ograniczenia bazy,
typowane wyniki oraz osobne testy wyścigów na PostgreSQL z `pcntl`. Domyślne
testy używają SQLite in-memory; jego blokada `FOR UPDATE` nie dowodzi
współbieżności produkcyjnej, więc test PostgreSQL jest częścią kontraktu F-02.

### Kluczowe odkrycia

- NFR-006 definiuje wspólny limit operacji zapisujących, nie koszt ani liczbę
  pojedynczych requestów YouTube: `context/foundation/prd.md:119`.
- F-02 odblokowuje cztery niezależne przepływy, więc licznik nie może należeć do
  jednego eksportera lub synchronizatora: `context/foundation/roadmap.md:94`.
- Potwierdzony manifest jest stabilnym wejściem przyszłego eksportu, ale nie
  jest jeszcze trwałą operacją zapisu: `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:13`.
- Potwierdzenie review jest transakcyjnie idempotentne i nie powinno zużywać
  slotu przed faktycznym rozpoczęciem przyszłego eksportu:
  `app/Actions/ExportReviews/ConfirmExportReview.php:26`.
- Budżet wyszukiwania jest oparty na cache i ma inną jednostkę rozliczenia:
  `app/Integrations/ExportMatching/YouTubeSearchBudget.php:8`.
- Produkcyjne środowisko oraz pełny job CI używają PostgreSQL, co pozwala
  udowodnić rzeczywistą serializację rekordów:
  `.github/workflows/ci.yml:84`.
- Oficjalny dzień kwoty YouTube resetuje się o północy czasu pacyficznego;
  implementacja musi używać `America/Los_Angeles`, a nie strefy aplikacji UTC.

## Pożądany stan końcowy

- Jeden typowany kontrakt przyjmuje zamknięty typ operacji i stabilne ID
  nadane oraz utrwalone przez konsumenta przed próbą dopuszczenia.
- Pierwsze poprawne wywołanie trwale zużywa jeden slot i zwraca
  `admitted-new`; każde późniejsze wywołanie tego samego klucza zwraca
  `admitted-existing`, również po zmianie dnia kwoty.
- Po osiągnięciu dziennego limitu nowy klucz zwraca `limit-reached`, dzień
  kwoty i moment następnego resetu, nie tworząc rezerwacji ani nie zmieniając
  licznika.
- Awaria konfiguracji, bazy lub uzyskania blokady kończy się typowanym
  `temporarily-unavailable`; konsument nie może wtedy rozpocząć zapisu.
- Skonfigurowany dodatni limit ma wartość domyślną `5`. Pierwsza nowa operacja
  dnia zapisuje jego snapshot, a zmiana konfiguracji obowiązuje od następnego
  dnia kwoty.
- Wszystkie nowe operacje współdzielą globalny limit bez względu na użytkownika,
  konto, typ przyszłego przepływu lub liczbę workerów.
- Test PostgreSQL dowodzi, że przy `limit + 1` równoległych nowych kluczach
  powstaje dokładnie `limit` rezerwacji, a równoległe próby jednego klucza
  zużywają dokładnie jeden slot.

## Czego NIE robimy

- Nie implementujemy eksportu zarządzanego ani eksportu na powiązane konto.
- Nie implementujemy synchronizacji źródła ani naprawy rozbieżności.
- Nie dodajemy tras, kontrolerów, Livewire, komunikatów ekranowych ani
  powiadomień; przyszłe wycinki mapują typowany wynik na własny UX.
- Nie wykonujemy żadnego requestu YouTube i nie dodajemy klienta zapisu.
- Nie modyfikujemy `ExportReview`, jego potwierdzenia ani zamrożonego manifestu.
- Nie łączymy licznika zapisów z `YouTubeSearchBudget` ani cache.
- Nie zwalniamy i nie refundujemy dopuszczenia po awarii, anulowaniu lub braku
  potwierdzonego requestu providera.
- Nie usuwamy historycznych rezerwacji i nie wprowadzamy retencji, która
  osłabiłaby bezterminową idempotencję.
- Nie implementujemy mechaniki deploymentu, migracji, blokad lub rollbacku
  zewnętrznego PaaS.

## Podejście do implementacji

Subsystem `YouTubeWriteAdmission` udostępni wąski port aplikacyjny. Konsument
przekaże jeden z zamkniętych typów odpowiadających planowanym przepływom oraz
niepuste, ograniczone długością trwałe ID. Wynik będzie readonly DTO z jednym z
trzech stanów biznesowych, dniem kwoty i absolutnym momentem resetu. Błędy
techniczne pozostaną poza stanem biznesowym jako osobny, bezpieczny wyjątek.

Trwałość zapewnią dwie addytywne tabele. Pojedynczy rekord stanu przechowa
bieżący dzień `America/Los_Angeles`, liczbę dopuszczeń i snapshot limitu.
Niezmienna księga przechowa każdą przyjętą parę `operation_type + operation_id`,
jej pierwotny dzień i czas dopuszczenia. Unikalność pary jest ostatecznym
zabezpieczeniem ponowień.

Akcja otworzy własną, krótką transakcję z ograniczonym ponowieniem błędów
współbieżności, zablokuje globalny rekord, a czas i konfigurację odczyta dopiero
po uzyskaniu blokady. Następnie zwróci istniejącą rezerwację albo przesunie stan
wyłącznie do późniejszego dnia, sprawdzi snapshot limitu oraz atomowo zapisze
rezerwację i licznik. Żadna blokada bazy nie pozostaje utrzymana podczas OAuth
lub requestu sieciowego.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Dzień i następny reset należy obliczać w IANA `America/Los_Angeles`; następny
reset to następna lokalna północ, nie `+24 godziny`. Przy cofnięciu zegara do
dnia wcześniejszego niż zapisany stan nieznany klucz musi zakończyć się
fail-closed zamiast ponownie otworzyć zużyty dzień. Wcześniej dopuszczony klucz
nadal zwraca `admitted-existing` z pierwotnym dniem i resetem, bez zmiany stanu.

### Sekwencjonowanie stanu

Bramka musi odrzucić wywołanie wewnątrz już otwartej transakcji aplikacyjnej.
Sukces wolno zwrócić dopiero po zatwierdzeniu własnej transakcji, a konsument
może wykonać pierwszy zapis YouTube dopiero po otrzymaniu tego wyniku.

### Oczekiwanie na blokadę i retry

Implementacja używa wewnętrznych stałych `LOCK_TIMEOUT_MS = 1000` oraz
`MAX_TRANSACTION_ATTEMPTS = 3`. Na PostgreSQL każda próba ustawia wewnątrz
transakcji `SET LOCAL lock_timeout` przed pobraniem globalnej blokady. Deadlock
lub serialization failure może ponowić całą transakcję najwyżej do limitu prób;
SQLSTATE `55P03` nie jest ponawiany i natychmiast staje się
`YouTubeWriteAdmissionUnavailable`. SQLite nie wykonuje SQL zależnego od
PostgreSQL. Wyczerpanie prób nigdy nie zwraca wyniku dopuszczenia.

### Debugowanie i obserwowalność

`limit-reached` jest oczekiwanym wynikiem biznesowym, natomiast błędna
konfiguracja, timeout blokady, deadlock i niejednoznaczny commit są
`temporarily-unavailable`. Wyniki i logi nie przechowują tokenów, providerowych
odpowiedzi, ID kont ani danych playlisty; stabilne ID operacji musi być lokalnym,
niepoufnym identyfikatorem.

## Phase 1: Polityka i kontrakt dopuszczenia

### Przegląd

Faza utrwala zmieniony kontrakt produktu, symboliczną konfigurację i typowane
API, zanim powstanie trwały mechanizm rezerwacji.

### Wymagane zmiany

#### 1. Kontrakt produktu i roadmapy

**Pliki**: `context/foundation/prd.md`, `context/foundation/roadmap.md`

**Cel**: Utrwalić, że globalny limit nowych logicznych zapisów YouTube jest
konfigurowalnym dodatnim limitem z wartością domyślną pięć, resetowanym według
dnia kwoty providera. Zachować bezterminowe użycie pierwotnej rezerwacji przez
retry oraz odmowę przed pierwszą mutacją.

**Kontrakt**: NFR-006, warunek ukończenia kamienia milowego, indeks F-02 i opis
F-02 używają tego samego sformułowania; status roadmapy pozostaje jednokierunkowo
`planning` podczas planowania i przechodzi do `in-progress` dopiero przy
implementacji.

#### 2. Symboliczna konfiguracja

**Pliki**: `.env.example`, `config/services.php`

**Cel**: Dodać jeden jawny klucz runtime dla dziennego limitu zapisów bez
łączenia go z limitem wyszukiwania lub poświadczeniami.

**Kontrakt**: `YOUTUBE_WRITE_DAILY_LIMIT` ma domyślną wartość `5` i musi być
dodatnią liczbą całkowitą mieszczącą się w kolumnie stanu. `config/services.php`
zachowuje surową wartość z env bez wczesnego rzutowania na `int`; akcja wykonuje
ścisłe parsowanie canonical decimal integer dopiero pod blokadą. `0`, wartości
ujemne, zapis zmiennoprzecinkowy, częściowo numeryczny taki jak `5x` oraz
przepełnienie nie są cicho korygowane i powodują fail-closed. Strefa dnia kwoty
jest stałą semantyką providera, a nie ustawieniem operatora.

#### 3. Zamknięty typ operacji i wynik

**Pliki**:
`app/Integrations/YouTubeWriteAdmission/YouTubeWriteOperationType.php`,
`app/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionStatus.php`,
`app/Integrations/YouTubeWriteAdmission/Data/YouTubeWriteAdmissionResult.php`,
`app/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionUnavailable.php`

**Cel**: Zdefiniować stabilne słownictwo dla czterech przyszłych konsumentów,
trzech wyników biznesowych i osobnej awarii technicznej.

**Kontrakt**: Typy odpowiadają managed export, linked export, source sync i
drift recovery. Wynik rozróżnia `admitted-new`, `admitted-existing` oraz
`limit-reached` i zawiera pierwotny lub bieżący dzień kwoty oraz odpowiadający
mu reset jako immutable instant. Dane stanu wzajemnie wykluczają niepoprawne
kombinacje, np. odmowa nie ma ID rezerwacji.

#### 4. Port przyszłych konsumentów

**Plik**:
`app/Integrations/YouTubeWriteAdmission/Contracts/AdmitYouTubeWrite.php`

**Cel**: Zapewnić jedyny publiczny punkt wejścia, który przyszłe joby eksportu
i synchronizacji mogą wywołać bez znajomości tabel lub algorytmu blokowania.

**Kontrakt**: Metoda przyjmuje zamknięty typ i stabilne ID operacji oraz zwraca
typowany wynik albo sygnalizuje `YouTubeWriteAdmissionUnavailable`. ID jest
niepuste, ograniczone długością i musi istnieć przed wejściem do bramki.

#### 5. Kontrakt źródła i test DTO

**Pliki**: `scripts/verify-source-contract`,
`tests/Unit/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionContractTest.php`

**Cel**: Chronić obecność nowych stabilnych plików i niezmienniki wyniku bez
uruchamiania bazy.

**Kontrakt**: Każdy nowy plik PHP jest obecny w manifeście źródła, a test
pokrywa dokładne typy, fabryki wyniku, niepoprawne kombinacje i kontrakt błędu.

### Kryteria sukcesu

#### Automated Verification

- Kontrakt typów i wyniku przechodzi:
  `php artisan test tests/Unit/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionContractTest.php`.
- Manifest źródła obejmuje każdy nowy stabilny plik:
  `sh scripts/verify-source-contract --worktree`.
- Formatowanie kontraktu przechodzi: `vendor/bin/pint --test`.

#### Manual Verification

- Przegląd potwierdza zgodność NFR-006, wszystkich wystąpień F-02 i
  symbolicznej konfiguracji: domyślnie pięć, wartość dodatnia, dzień PT.
- Przegląd potwierdza, że kontrakt nie zależy od eksportera, synchronizatora,
  cache, tokenu, konta ani kodu zewnętrznego PaaS.

**Uwaga implementacyjna**: Po zakończeniu fazy i automatycznych weryfikacjach
zatrzymaj się na ręczne potwierdzenie przed Fazą 2.

---

## Phase 2: Trwała i atomowa rezerwacja

### Przegląd

Faza dodaje addytywną księgę oraz implementację portu, która liniaryzuje
wszystkie nowe dopuszczenia i zachowuje retry bezterminowo.

### Wymagane zmiany

#### 1. Schemat globalnego stanu i księgi

**Plik**:
`database/migrations/2026_09_14_000300_create_youtube_write_admission_tables.php`

**Cel**: Utworzyć minimalny trwały stan limitu oraz niezmienną księgę
przyjętych operacji bez danych użytkownika lub providera.

**Kontrakt**: Tabela stanu zawiera dokładnie jeden rekord z primary key
`singleton_key`. Kolumna jest enumem dopuszczającym wyłącznie wartość `global`,
co daje bazodanowy `CHECK`, a migracja tworzy ten rekord z nullable dniem,
licznikiem zero i nullable snapshotem limitu. Akcja zawsze blokuje klucz
`global` i kończy się fail-closed, jeśli rekordu brakuje. Tabela rezerwacji
zawiera typ, stabilne ID, dzień i czas dopuszczenia; unikalność
`(operation_type, operation_id)` zapobiega podwójnemu naliczeniu, a indeks dnia
wspiera audyt. `down()` zawsze odmawia destrukcyjnego usunięcia księgi i stanu
oraz pozostawia obie tabele bez zmian. Ich usunięcie wymaga osobnej, jawnie
destrukcyjnej zmiany; `migrate:fresh` pozostaje dozwolone wyłącznie na
disposable bazie.

#### 2. Modele trwałego stanu

**Pliki**: `app/Models/YouTubeWriteQuotaState.php`,
`app/Models/YouTubeWriteAdmission.php`

**Cel**: Zapewnić typowane mapowanie pól i wąskie operacje potrzebne akcji oraz
testom bez eksponowania mutowalnej księgi konsumentom.

**Kontrakt**: Dzień jest immutable date, czas dopuszczenia immutable datetime,
typ operacji używa enum, a pola licznika i limitu są integer. Model rezerwacji
nie ma relacji do użytkownika, konta, playlisty ani review.

#### 3. Transakcyjna implementacja portu

**Plik**:
`app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php`

**Cel**: Atomowo rozstrzygać nowe dopuszczenie, retry i wyczerpanie limitu dla
wszystkich procesów aplikacji.

**Kontrakt**: Akcja waliduje wejście przed bazą, odrzuca zagnieżdżoną
transakcję i wykonuje własną transakcję z `LOCK_TIMEOUT_MS = 1000` oraz
`MAX_TRANSACTION_ATTEMPTS = 3`. PostgreSQL ustawia lokalny timeout przed
blokadą; deadlock i serialization failure mogą ponowić całą transakcję, lecz
SQLSTATE `55P03` od razu oznacza niedostępność. SQLite nie wykonuje SQL
zależnego od PostgreSQL. Po zablokowaniu stanu akcja odczytuje czas i
konfigurację, zwraca istniejącą rezerwację bez zmiany licznika, przesuwa stan
wyłącznie do późniejszego dnia, a dla nowej operacji zapisuje rezerwację oraz
inkrementuje licznik w jednym commicie. Każdy wyczerpany błąd techniczny jest
mapowany na `YouTubeWriteAdmissionUnavailable` po rollbacku.

#### 4. Rejestracja implementacji

**Pliki**: `app/Providers/YouTubeWriteAdmissionServiceProvider.php`,
`bootstrap/providers.php`

**Cel**: Powiązać publiczny port z jedną implementacją bez wymagania od
przyszłych konsumentów znajomości klasy konkretnej.

**Kontrakt**: Kontener rozwiązuje `AdmitYouTubeWrite` do
`ReserveYouTubeWrite`; provider nie rejestruje tras, harmonogramu ani klienta
HTTP.

#### 5. Test migracji i zachowania

**Pliki**:
`tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionMigrationTest.php`,
`tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php`,
`scripts/verify-source-contract`

**Cel**: Udowodnić schemat, sekwencyjną semantykę, czas, konfigurację i
fail-closed na domyślnym SQLite bez przypisywania mu gwarancji współbieżności.

**Kontrakt**: Testy pokrywają zastosowanie migracji, bazodanową odmowę drugiego
rekordu stanu, fail-closed po usunięciu singletonu, zawsze odmawiany `down()`
pozostawiający obie tabele i ich dane bez zmian, unikalność klucza operacji,
pierwsze N sukcesów i N+1 odmowę bez side effect, retry przed i po resecie,
różne typy z tym samym ID, trwałość po nowej instancji procesu, zużycie
rezerwacji mimo późniejszej awarii konsumenta, snapshot zmiany limitu,
konfigurację poprawną oraz odrzucenie `0`, wartości ujemnej, `5x`, zapisu
zmiennoprzecinkowego i przepełnienia, zagnieżdżoną transakcję, rollback błędu,
fail-closed nowego klucza i `admitted-existing` znanego klucza po cofnięciu
zegara oraz granice PST/PDT.

### Kryteria sukcesu

#### Automated Verification

- Addytywna migracja, ograniczenia i bezpieczny rollback przechodzą:
  `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionMigrationTest.php`.
- Pełna sekwencyjna macierz limitu, retry, konfiguracji, czasu i awarii
  przechodzi:
  `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php`.
- Manifest źródła po dodaniu implementacji przechodzi:
  `sh scripts/verify-source-contract --worktree`.
- Regresje PHP nie występują: `composer test && vendor/bin/pint --test`.

#### Manual Verification

- Przegląd transakcji potwierdza kolejność: commit dopuszczenia, zwolnienie
  blokady, dopiero potem przyszły OAuth/request YouTube.
- Inspekcja schematu potwierdza brak tokenów, ID kont, danych użytkownika,
  playlist i providerowych payloadów oraz brak ścieżki zwalniania rezerwacji.

**Uwaga implementacyjna**: Po zakończeniu fazy i automatycznych weryfikacjach
zatrzymaj się na ręczne potwierdzenie przed Fazą 3.

---

## Phase 3: Dowód współbieżności i gotowość integracyjna

### Przegląd

Faza dowodzi gwarancji na produkcyjnym silniku, zamyka kontrakt źródła i
przygotowuje addytywny schemat do kontrolowanego wydania bez uruchamiania
realnych zapisów YouTube.

### Wymagane zmiany

#### 1. Wyścigi PostgreSQL

**Plik**:
`tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php`

**Cel**: Udowodnić, że `FOR UPDATE` i unikalność zachowują globalny limit pod
rzeczywistą równoległością wielu połączeń.

**Kontrakt**: Test `pcntl` używa bariery i osobnych połączeń. Dla limitu N oraz
N+1 różnych kluczy otrzymuje dokładnie N `admitted-new` i jedno
`limit-reached`; dla wielu prób jednego klucza otrzymuje jeden
`admitted-new`, resztę `admitted-existing`, jeden wpis księgi i jeden wzrost
licznika. Osobny przypadek dowodzi przypisania do dnia obowiązującego po
uzyskaniu blokady, nie przed oczekiwaniem.

#### 2. Odporność na proces i heterogeniczną konfigurację

**Plik**:
`tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php`

**Cel**: Zweryfikować restart procesu, bounded contention oraz rolling deploy,
w którym workery widzą różne wartości konfiguracji.

**Kontrakt**: Pierwsza nowa operacja pod blokadą ustala limit dnia; późniejsze
workery nie zmieniają go do następnej lokalnej północy. Test PostgreSQL
potwierdza timeout po 1000 ms, natychmiastowe mapowanie `55P03` bez retry oraz
najwyżej trzy próby pełnej transakcji dla deadlocku lub serialization failure.
SQLite nie otrzymuje polecenia `SET LOCAL`. Timeout lub wyczerpane retry nie
zwracają wyniku dopuszczenia, a ponowienie po niejednoznacznym commicie z tym
samym kluczem bezpiecznie rozpoznaje stan.

#### 3. Kontrakt źródła i pełne bramki

**Pliki**: `scripts/verify-source-contract`, `.github/workflows/ci.yml`

**Cel**: Zagwarantować obecność wszystkich nowych plików i wykonanie testu
PostgreSQL w istniejącym pełnym jobie CI.

**Kontrakt**: Manifest zawiera każdy stabilny plik aplikacji, migracji i testu.
Istniejący job PostgreSQL uruchamia test bez nowego sekretu lub sieci; workflow
zmienia się tylko wtedy, gdy bieżące odkrywanie testów nie obejmuje nowej klasy.

#### 4. Instrukcja integracyjna dla przyszłych wycinków

**Plik**: `README.md`

**Cel**: Udokumentować minimalny publiczny kontrakt konsumenta i symboliczną
konfigurację bez opisywania implementacji zewnętrznego PaaS.

**Kontrakt**: Konsument najpierw utrwala stabilne ID, wywołuje bramkę poza
własną transakcją, czeka na committed result i tylko dla obu stanów admitted
może rozpocząć zapis. `limit-reached` oraz awaria techniczna oznaczają zero
mutacji; retry zawsze używa tej samej pary typu i ID.

### Kryteria sukcesu

#### Automated Verification

- Wyścigi na PostgreSQL przechodzą w środowisku z `pgsql` i `pcntl`:
  `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php`.
- Pełny zestaw aplikacji przechodzi na skonfigurowanej bazie:
  `composer test`.
- Formatowanie i produkcyjny frontend przechodzą:
  `vendor/bin/pint --test && npm run build`.
- Kontrakt źródła jest kompletny: `sh scripts/verify-source-contract --worktree`.

#### Manual Verification

- Na disposable PostgreSQL pięć nowych kluczy przy limicie pięć daje pięć
  dopuszczeń, szósty odmowę z poprawnym resetem PT, a retry po restarcie procesu
  nie zmienia licznika.
- Kandydat addytywnego schematu jest gotowy do publicznej operacji
  `schema-release`; wdrożenie, mutacja produkcyjnej bazy i realny smoke YouTube
  pozostają poza wykonaniem tej fazy.
- Przegląd końcowy potwierdza, że F-02 nie wykonuje requestów YouTube i może być
  użyte bez zmiany kontraktu przez S-06, S-07, S-08 i S-09.

**Uwaga implementacyjna**: Po zakończeniu fazy i automatycznych weryfikacjach
zatrzymaj się na końcowe ręczne potwierdzenie.

---

## Strategia testowania

### Testy jednostkowe

- Zamknięte typy operacji i statusów.
- Poprawne oraz niepoprawne kombinacje pól readonly result DTO.
- Stabilna, poufna powierzchnia wyjątku technicznego.

### Testy integracyjne

- Migracja obu tabel, singleton, unikalność i bezpieczny rollback.
- Granice `1`, domyślne `5` i wartości większe niż `5`.
- N nowych rezerwacji, N+1 odmowa bez częściowego zapisu.
- Retry tego samego klucza przed i długo po resecie.
- Jeden globalny pool dla wszystkich typów i użytkowników.
- Snapshot konfiguracji obowiązujący do następnego dnia PT.
- Północ PST/PDT, następna lokalna północ i cofnięcie zegara.
- Błąd bazy, blokady, zagnieżdżonej transakcji i niejednoznacznego commitu.
- Równoległe różne oraz identyczne klucze na PostgreSQL.

### Kroki testowania ręcznego

1. Na disposable PostgreSQL ustawić limit `5` i dopuścić pięć różnych kluczy.
2. Potwierdzić odmowę szóstego klucza, brak szóstego wpisu oraz niezmieniony
   licznik.
3. Uruchomić nowy proces i ponowić jeden dopuszczony klucz; potwierdzić
   `admitted-existing`, pierwotny dzień i brak zmiany licznika.
4. Zmienić limit w tym samym dniu i potwierdzić, że snapshot pozostaje stały;
   po następnej północy PT pierwsza nowa operacja przyjmuje nową wartość.
5. Wymusić niedostępność lub timeout bazy i potwierdzić typowaną awarię oraz
   brak jakiejkolwiek ścieżki do requestu YouTube.

## Uwagi dotyczące wydajności

Globalny rekord celowo serializuje bardzo małą ścieżkę krytyczną. Przy limicie
rzędu kilku operacji dziennie koszt jest pomijalny, a pojedyncza kolejność daje
prostszy i mocniejszy dowód niż rozproszone liczniki. Transakcja obejmuje tylko
walidację stanu i dwa małe zapisy; nigdy nie obejmuje sieci, OAuth ani pracy nad
playlistą. Oczekiwanie na blokadę jest ograniczone i kończy się fail-closed.

## Uwagi dotyczące migracji

Migracja jest wyłącznie addytywna i nie zmienia istniejących rekordów. Release
aplikacji wymagający tabel wchodzi po ich utworzeniu przez publiczną operację
PaaS `schema-release`; plan nie zależy od sposobu, w jaki PaaS transportuje
konfigurację lub wykonuje wdrożenie.

Rollback obrazu aplikacji pozostaje zgodny z obecnością nieużywanych tabel.
Migracja zawsze odmawia `down()`, ponieważ warunkowe sprawdzenie pustej księgi
nie zabezpiecza przed równoległym dopuszczeniem, a jej usunięcie złamałoby
bezterminową idempotencję. `migrate:fresh` wolno używać tylko na disposable
bazie. Ewentualne przyszłe usunięcie danych wymaga osobnej, jawnie destrukcyjnej
zmiany i nowej decyzji produktowej.

## Referencje

- Zakres produktu i NFR-006: `context/foundation/prd.md:119`.
- Fundament F-02 i przyszli konsumenci: `context/foundation/roadmap.md:94`.
- Zasady repozytorium i granica PaaS: `AGENTS.md`.
- Reguły trwałych decyzji: `context/foundation/lessons.md`.
- Najbliższy kontrakt idempotentnego manifestu:
  `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php`.
- Osobny budżet odczytu, którego nie wolno używać:
  `app/Integrations/ExportMatching/YouTubeSearchBudget.php`.
- Wzorzec transakcji i blokady:
  `app/Actions/Playlists/UpdateBankPlaylistItems.php`.
- Wzorce testów PostgreSQL:
  `tests/Feature/PlaylistEditing/UpdateBankPlaylistItemsPostgresTest.php`,
  `tests/Feature/Playlists/PlaylistConcurrentImportTest.php`.
- Oficjalny reset i kwota YouTube:
  `https://developers.google.com/youtube/v3/determine_quota_cost`.
- Laravel 13 — transakcje i blokady pesymistyczne:
  `https://laravel.com/docs/13.x/queries#pessimistic-locking`.
- PostgreSQL — blokady wierszy:
  `https://www.postgresql.org/docs/current/explicit-locking.html`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Polityka i kontrakt dopuszczenia

#### Automated

- [x] 1.1 Kontrakt typów i wyniku przechodzi — f1c1b14
- [x] 1.2 Manifest źródła obejmuje każdy nowy stabilny plik — f1c1b14
- [x] 1.3 Formatowanie kontraktu przechodzi — f1c1b14

#### Manual

- [x] 1.4 Przegląd potwierdza zgodność NFR-006, F-02 i konfiguracji
- [x] 1.5 Przegląd potwierdza niezależność kontraktu od przyszłych konsumentów i PaaS

### Phase 2: Trwała i atomowa rezerwacja

#### Automated

- [x] 2.1 Addytywna migracja, ograniczenia i bezpieczny rollback przechodzą — 8722c66
- [x] 2.2 Sekwencyjna macierz limitu, retry, konfiguracji, czasu i awarii przechodzi — 8722c66
- [x] 2.3 Manifest źródła po dodaniu implementacji przechodzi — 8722c66
- [x] 2.4 Regresje PHP nie występują — 8722c66

#### Manual

- [x] 2.5 Przegląd potwierdza commit dopuszczenia przed przyszłym requestem YouTube
- [x] 2.6 Inspekcja schematu potwierdza minimalne dane i brak zwalniania rezerwacji

### Phase 3: Dowód współbieżności i gotowość integracyjna

#### Automated

- [x] 3.1 Wyścigi na PostgreSQL przechodzą — 197e83f
- [x] 3.2 Pełny zestaw aplikacji przechodzi — 197e83f
- [x] 3.3 Formatowanie i produkcyjny frontend przechodzą — 197e83f
- [x] 3.4 Kontrakt źródła jest kompletny — 197e83f

#### Manual

- [x] 3.5 Disposable PostgreSQL potwierdza limit, odmowę i retry po restarcie
- [x] 3.6 Kandydat addytywnego schematu jest gotowy do publicznej operacji schema-release
- [x] 3.7 Przegląd potwierdza brak requestów YouTube i gotowość dla S-06–S-09
