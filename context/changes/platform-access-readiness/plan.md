# Gotowość dostępu do Spotify i YouTube — plan implementacji

## Przegląd

Ta zmiana usuwa największe zewnętrzne ryzyko MVP przed budową importu, łączenia kont i eksportu. Ustala prawdziwy kontrakt możliwości Spotify i YouTube, dodaje bezpieczny kontrakt konfiguracji aplikacji oraz pozostawia powtarzalny runbook i sanitizowany dowód rzeczywistych prób na kontach technicznych i świeżych kontach testowych.

F-01 kończy się na gotowości deweloperskiej. Nie daje jeszcze publicznej gotowości produkcyjnej, nie wdraża funkcji playlist i nie przejmuje odpowiedzialności Managera za cykl życia sekretów kont technicznych.

## Analiza stanu obecnego

Roadmapa definiuje F-01 jako fundament blokujący S-02 oraz S-04–S-07, lecz folder zmiany dotąd nie istniał. Projekty deweloperskie, poświadczenia aplikacji oraz konta techniczne obu platform są aktywne według użytkownika, ale repozytorium nie zawiera jeszcze kontraktu konfiguracji ani trwałego dowodu, które możliwości zostały sprawdzone.

Kod ma jeden zewnętrzny przepływ OAuth — logowanie Google. Zapewnia on state/CSRF, neutralne błędy, ograniczanie ruchu i logowanie wyłącznie klasy wyjątku z identyfikatorem korelacji. `AuthIdentity` celowo nie przechowuje tokenów dostawcy. Produkcyjne ustawienia pochodzą z symbolicznych zmiennych środowiskowych, CI skanuje całą historię pod kątem sekretów i nie przyjmuje live credentials poza własnym `GITHUB_TOKEN`.

Najważniejszy drift produktowy dotyczy Spotify. Aktualne Web API nie udostępnia elementów dowolnej publicznej playlisty nowej aplikacji: odczyt pozycji wymaga autoryzowanego właściciela lub współpracownika. YouTube nadal pozwala czytać publiczne i unlisted playlisty przy użyciu API key, natomiast każdy zapis wymaga grantu OAuth użytkownika. Konto techniczne YouTube musi być zwykłym kontem Google z kanałem — nie service accountem.

### Kluczowe odkrycia

- F-01 odblokowuje pięć zależnych zmian, a jego nierozwiązana niewiadoma jest oznaczona jako blokująca (`context/foundation/roadmap.md:42`, `context/foundation/roadmap.md:79`).
- FR-004 nadal obiecuje import linku z obu platform i wymaga korekty dla Spotify (`context/foundation/prd.md:77`).
- NFR-003 wymaga poufności, minimalnych uprawnień i utraty dostępu po odłączeniu (`context/foundation/prd.md:113`).
- Konwencją repozytorium jest mapowanie `env()` w `config/services.php`, a bezpieczny przykład produkcyjny używa jawnych placeholderów (`config/services.php:38`, `.env.example:58`).
- CI nie może otrzymać sekretów platform: test utrwala, że jedynym sekretem workflow jest `GITHUB_TOKEN` (`tests/Unit/ProductionInfrastructureTest.php:37`).
- `AuthIdentity` jest tożsamością logowania, a test jawnie zabrania atrybutów `token` i `refresh_token` (`tests/Feature/Auth/AuthIdentityModelTest.php:65`).
- Wzorzec sanitizacji błędów zapisuje tylko klasę wyjątku i UUID korelacji, bez treści błędu dostawcy (`app/Http/Controllers/Auth/GoogleAuthController.php:56`, `tests/Feature/Auth/GoogleAuthenticationTest.php:227`).
- Przyszłe ręczne kontrole muszą mieć współczesny zapis z datą, środowiskiem i konkretną obserwacją bez sekretów, tokenów ani PII (`context/archive/2026-09-12-private-account-and-bank/reviews/manual-verification.md:48`).
- Każdy nowy trwały plik kodu, testu, skryptu lub dokumentacji musi wejść do kontraktu źródła (`scripts/verify-source-contract:14`, `tests/Unit/SourceContractTest.php:9`).

## Pożądany stan końcowy

Po wykonaniu planu dokumenty fundamentu nie obiecują zachowania niemożliwego w aktualnym Spotify API. Repozytorium definiuje symboliczne, fail-closed ustawienia obu platform bez prawdziwych wartości oraz wyraźnie oddziela dane aplikacji od zarządzanych poza repo poświadczeń kont technicznych.

Trwały runbook pozwala człowiekowi powtórzyć kontrolowane próby na dwuelementowych playlistach. Sanitizowana macierz dowodów wskazuje osobno wynik każdej zdolności dla Spotify i YouTube, konta technicznego i świeżego konta testowego. Każda wymagana pozycja ma `PASS`; dowolne `BLOCKED` zatrzymuje F-01 i wymaga korekty PRD/roadmapy przed rozpoczęciem zależnej zmiany.

Stan końcowy potwierdza wyłącznie:

- Spotify Development Mode dla jawnie ograniczonej grupy użytkowników;
- Google OAuth External/Testing, ze znanym siedmiodniowym cyklem refresh tokenów dla testowych scope'ów YouTube;
- dostępność odczytu i minimalnego zapisu wymaganych do rozpoczęcia prac deweloperskich;
- brak sekretów w repozytorium, CI, trwałych dowodach i logach aplikacji.

## Czego NIE robimy

- Nie implementujemy importu, dopasowywania, eksportu ani synchronizacji playlist.
- Nie dodajemy kontrolerów OAuth Spotify/YouTube, ekranów zgody ani interfejsu łączenia kont.
- Nie tworzymy modeli integracji, tabel tokenów ani migracji bazy danych; `auth_identities` pozostaje bez tokenów streamingowych.
- Nie tworzymy komendy Artisan ani trwałych klientów HTTP tylko na potrzeby prób F-01.
- Nie wkładamy live credentials do GitHub Actions i nie uruchamiamy testów platformowych w zwykłym CI.
- Nie obsługujemy importu dowolnej cudzej publicznej playlisty Spotify ani obejść opartych na scrapingu.
- Nie gwarantujemy ścisłej prywatności Spotify; kontraktem jest brak widoczności w profilu i wyszukiwarce przy możliwości dostępu przez link.
- Nie uzyskujemy Spotify Extended Quota ani publicznej akceptacji produkcyjnej Google OAuth.
- Nie implementujemy odłączenia i revokacji grantów użytkowników; należy to do S-04.
- Nie opisujemy, nie testujemy i nie zmieniamy mechaniki Managera. Manager jako zewnętrzny PaaS odpowiada za przechowywanie, dostarczanie i rotację poświadczeń kont technicznych.

## Podejście do implementacji

Najpierw trzeba naprawić trwałe założenia produktu, ponieważ każdy późniejszy klient Spotify odziedziczyłby obecny, niewykonalny kontrakt importu. W tej samej fazie aplikacja otrzyma wyłącznie symboliczny kontrakt konfiguracji dla dwóch providerów, chroniony testami fail-closed i istniejącymi bramkami bezpieczeństwa.

Następnie powstanie trwały runbook oparty na oficjalnych interfejsach dostawców. Runbook rozdzieli cztery konteksty tożsamości: dostęp aplikacyjny YouTube do danych publicznych, konto techniczne każdej platformy oraz świeże konto testowe każdej platformy. Operacje live pozostają świadomie ręczne, aby sekrety, quota i zewnętrzne mutacje nie trafiały do CI ani tymczasowego kodu aplikacji.

Na końcu operator wykona macierz prób, usunie ręcznie testowe playlisty i zapisze wyłącznie sanitizowane obserwacje. Wynik będzie atomową bramką: wszystkie wymagane zdolności przechodzą albo F-01 pozostaje zablokowane.

## Krytyczne szczegóły implementacji

### Specyfikacja doświadczenia użytkownika

Zmiana znaczenia prywatności musi być jednoznaczna we wszystkich dokumentach: Spotify `public=false` oznacza brak publikacji w profilu i wyszukiwarce, lecz nie jest kontrolą dostępu dla osoby posiadającej link. YouTube eksport zarządzany używa `unlisted`, zgodnie z potrzebą stałego linku bez publicznej ekspozycji.

### Debugowanie i obserwowalność

Trwały dowód może zawierać datę UTC, środowisko, lokalny alias projektu/konta, scope'y, nazwę operacji, kategorię odpowiedzi, widoczność, zużycie/stan quota i rezultat. Nie może zawierać tokenu, client secret, authorization code, pełnej odpowiedzi providera, e-maila, platformowego ID konta/playlisty ani prywatnego URL-a.

## Faza 1: Urealnienie kontraktu produktu i konfiguracji

### Przegląd

Ta faza usuwa sprzeczność Spotify z PRD i ustanawia minimalny, bezpieczny kontrakt ustawień, które późniejsze wycinki będą konsumować. Nie nawiązuje jeszcze połączeń sieciowych i nie utrwala tokenów użytkowników.

### Wymagane zmiany

#### 1. Ograniczenia importu i widoczności w PRD

**Plik**: `context/foundation/prd.md`

**Cel**: Urealnić FR-004 oraz powiązane kryteria i guardraile, aby nie obiecywały odczytu dowolnej cudzej playlisty Spotify. Doprecyzować widoczność wyników zgodnie z rzeczywistymi gwarancjami obu platform.

**Kontrakt**: Spotify importuje po OAuth wyłącznie playlistę własną lub współdzieloną z użytkownikiem. YouTube może importować publiczną lub unlisted playlistę przez API key, a prywatną po OAuth właściciela. Spotify `public=false` znaczy „niewidoczna w profilu i wyszukiwarce, potencjalnie dostępna przez link”; YouTube używa `unlisted` dla wyniku zarządzanego. Zmiana nie rozszerza MVP poza Spotify i YouTube.

#### 2. Synchronizacja zależności roadmapy

**Plik**: `context/foundation/roadmap.md`

**Cel**: Przenieść zweryfikowane ograniczenia do opisów F-01 i S-02 oraz usunąć rozstrzygniętą niewiadomą o istnieniu projektów i kont. Zachować F-01 jako bramkę wszystkich zależnych wycinków.

**Kontrakt**: F-01 pozostaje `planning` do rozpoczęcia implementacji, a następnie podlega normalnemu cyklowi statusów. S-02 mówi o odmiennych warunkach importu Spotify i YouTube. Dowolne niepowodzenie live matrix pozostaje blockerem, a nie milczącym fallbackiem do jednej platformy.

#### 3. Symboliczna konfiguracja providerów

**Pliki**: `.env.example`, `config/services.php`

**Cel**: Zdefiniować stabilne nazwy ustawień aplikacji dla Spotify i YouTube, zachowując istniejący wzorzec `env()` → `config()` i fail-closed produkcyjne placeholdery.

**Kontrakt**: Sekcje `services.spotify` i `services.youtube` mają dokładne mapowanie z poniższej tabeli. YouTube świadomie współdzieli `GOOGLE_CLIENT_ID` i `GOOGLE_CLIENT_SECRET` z istniejącym Google Login, lecz używa oddzielnego grantu, tokenów, oczekiwanego konta i scope'ów; nie istnieje fallback pomiędzy tożsamością logowania, kontem technicznym i testerem. Wszystkie sekrety mają w `.env.example` wartość `__REQUIRED_RUNTIME_SECRET__`, callbacki używają domeny `.invalid`, a niesekretne metadane techniczne mają jawnie niepoprawny placeholder `__REQUIRED_RUNTIME_VALUE__`. Manager dostarcza wartości w runtime przez te publiczne nazwy, natomiast sposób ich składowania i rotacji jest poza repozytorium.

| Zmienna środowiskowa | Klucz `config()` | Znaczenie |
| --- | --- | --- |
| `SPOTIFY_CLIENT_ID` | `services.spotify.client_id` | Identyfikator aplikacji Spotify |
| `SPOTIFY_CLIENT_SECRET` | `services.spotify.client_secret` | Sekret aplikacji Spotify |
| `SPOTIFY_REDIRECT_URI` | `services.spotify.redirect` | Przyszły callback OAuth użytkownika; URL `.invalid` w przykładzie |
| `SPOTIFY_TECHNICAL_EXPECTED_ACCOUNT_ID` | `services.spotify.technical.expected_account_id` | Oczekiwana tożsamość konta technicznego |
| `SPOTIFY_TECHNICAL_ACCESS_TOKEN` | `services.spotify.technical.access_token` | Krótkotrwały token techniczny |
| `SPOTIFY_TECHNICAL_REFRESH_TOKEN` | `services.spotify.technical.refresh_token` | Grant dostępu technicznego offline |
| `SPOTIFY_TECHNICAL_ACCOUNT_ID` | `services.spotify.technical.account_id` | Tożsamość potwierdzona podczas autoryzacji |
| `SPOTIFY_TECHNICAL_SCOPES` | `services.spotify.technical.scopes` | Dokładny przyznany zbiór scope'ów |
| `SPOTIFY_TECHNICAL_ACCESS_EXPIRES_AT` | `services.spotify.technical.access_expires_at` | Czas wygaśnięcia access tokenu w UTC |
| `GOOGLE_CLIENT_ID` | `services.youtube.client_id` | Współdzielony klient OAuth Google, ale nie grant Google Login |
| `GOOGLE_CLIENT_SECRET` | `services.youtube.client_secret` | Współdzielony sekret klienta OAuth Google |
| `YOUTUBE_REDIRECT_URI` | `services.youtube.redirect` | Przyszły callback OAuth użytkownika; URL `.invalid` w przykładzie |
| `YOUTUBE_API_KEY` | `services.youtube.api_key` | Klucz wyłącznie do publicznego odczytu YouTube |
| `YOUTUBE_TECHNICAL_EXPECTED_ACCOUNT_ID` | `services.youtube.technical.expected_account_id` | Oczekiwana tożsamość konta technicznego |
| `YOUTUBE_TECHNICAL_ACCESS_TOKEN` | `services.youtube.technical.access_token` | Krótkotrwały token techniczny |
| `YOUTUBE_TECHNICAL_REFRESH_TOKEN` | `services.youtube.technical.refresh_token` | Grant dostępu technicznego offline |
| `YOUTUBE_TECHNICAL_ACCOUNT_ID` | `services.youtube.technical.account_id` | Tożsamość potwierdzona podczas autoryzacji |
| `YOUTUBE_TECHNICAL_SCOPES` | `services.youtube.technical.scopes` | Dokładny przyznany zbiór scope'ów |
| `YOUTUBE_TECHNICAL_ACCESS_EXPIRES_AT` | `services.youtube.technical.access_expires_at` | Czas wygaśnięcia access tokenu w UTC |

`Fail-closed` w F-01 oznacza brak działających domyślnych credentiali, brak fallbacku między principalami oraz bezpieczną odmowę dopiero przy użyciu nieskonfigurowanej integracji. Sama obecność placeholderów nie blokuje startu aplikacji, ponieważ F-01 nie wdraża jeszcze produkcyjnych klientów Spotify/YouTube.

#### 4. Kontrakt automatyczny konfiguracji

**Pliki**: `tests/Unit/PlatformAccessConfigurationTest.php`, `tests/Unit/PlatformAccessDocumentationTest.php`, `tests/Unit/ProductionInfrastructureTest.php`, `scripts/verify-source-contract`

**Cel**: Chronić obecność i rozdzielenie ustawień obu platform, brak pustych sekretów oraz brak live credentials w workflowach. Włączyć nowe trwałe pliki do istniejącego manifestu źródła.

**Kontrakt**: Test konfiguracji sprawdza każde mapowanie z tabeli, dokładne placeholdery, rozdzielenie technicznych grantów oraz jawne współdzielenie wyłącznie klienta OAuth Google między loginem i YouTube. Potwierdza również, że brak lub placeholder nie daje działającego credentialu, ale nie blokuje startu aplikacji. Test dokumentacji utrwala ograniczenie importu Spotify, semantykę widoczności oraz asymetrię odczytu YouTube. Istniejący test CI nadal dopuszcza tylko `GITHUB_TOKEN`; nie wolno dodawać Spotify/YouTube secrets do `.github/workflows/ci.yml`. Kontrakt źródła obejmuje oba nowe testy i późniejszy runbook.

#### 5. Dokumentacja granicy sekretów

**Plik**: `README.md`

**Cel**: Wyjaśnić lokalny i produkcyjny kontrakt ustawień bez publikowania instrukcji operacyjnych Managera.

**Kontrakt**: Dokument wymienia symboliczne zmienne, klasyfikuje wartości wrażliwe, zakazuje logowania lub utrwalania tokenów oraz odsyła do runbooka. Stwierdza, że Manager dostarcza i rotuje poświadczenia kont technicznych jako zewnętrzny PaaS; nie zawiera ścieżek hosta, uprawnień plików ani jego wewnętrznych komend.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Kontrakt konfiguracji platform przechodzi: `php artisan test tests/Unit/PlatformAccessConfigurationTest.php tests/Unit/ProductionInfrastructureTest.php`.
- Kontrakt źródła obejmuje nowe trwałe pliki i przechodzi: `sh scripts/verify-source-contract --worktree`.
- Kontrakt dokumentacji potwierdza, że fundamenty nie obiecują importu arbitralnej cudzej playlisty Spotify ani ścisłej prywatności gwarantowanej przez `public=false`: `php artisan test tests/Unit/PlatformAccessDocumentationTest.php`.

#### Weryfikacja ręczna

- Właściciel produktu potwierdza, że zmienione FR-004, opisy eksportu i S-02 zachowują zamierzoną wartość MVP przy ograniczeniach Spotify/YouTube.
- Operator potwierdza, że symboliczne nazwy odpowiadają publicznemu kontraktowi runtime Managera, bez ujawniania lub kopiowania wartości.

**Uwaga implementacyjna**: Po zakończeniu fazy i przejściu automatycznych weryfikacji zatrzymaj się na ręczne potwierdzenie obu punktów przed rozpoczęciem fazy 2.

---

## Faza 2: Runbook i macierz akceptacyjna

### Przegląd

Ta faza pozostawia trwałą, powtarzalną instrukcję weryfikacji bez budowania jednorazowych klientów aplikacyjnych. Runbook określa minimalne scope'y, kontrolowane zasoby, oczekiwane wyniki, quota i zasady bezpiecznego zapisu dowodów.

### Wymagane zmiany

#### 1. Trwały runbook gotowości

**Plik**: `docs/platform-access-readiness.md`

**Cel**: Opisać procedurę, którą człowiek może bezpiecznie powtórzyć po zmianie konfiguracji lub API, korzystając z oficjalnych narzędzi i interfejsów dostawców.

**Kontrakt**: Runbook ma jawne preconditions, nazwy środowisk, zasady wyboru izolowanych playlist i tabelę `PASS/BLOCKED`. Nie umieszcza tokenów w argumentach poleceń, historii powłoki, URL-ach, zrzutach ani repozytorium. Rozdziela kroki Spotify i YouTube oraz konta techniczne i świeże konta testowe. Każda mutacja wymaga zanotowania zasobu do ręcznego usunięcia.

#### 2. Macierz możliwości Spotify

**Plik**: `docs/platform-access-readiness.md`

**Cel**: Ustalić minimalny dostęp wymagany przez przyszłe S-02, S-04, S-05, S-06 i S-07 bez proszenia o zbędne scope'y.

**Kontrakt**: Runbook sprawdza Authorization Code z dostępem offline, stabilny identyfikator konta, odczyt własnej i współdzielonej playlisty, oczekiwaną odmowę odczytu cudzych elementów, utworzenie niepublicznej playlisty, dodanie dwóch pozycji, zmianę kolejności lub zawartości oraz potwierdzenie braku publikacji w profilu/wyszukiwarce. Scope'y odczytu i zapisu są opisane per przepływ; e-mail nie jest kluczem konta. Development Mode, limit użytkowników i wymaganie Premium właściciela aplikacji są jawne.

#### 3. Macierz możliwości YouTube

**Plik**: `docs/platform-access-readiness.md`

**Cel**: Oddzielić tani odczyt danych publicznych od operacji wymagających szerokiego grantu użytkownika i kanału YouTube.

**Kontrakt**: Runbook sprawdza API key dla publicznej/unlisted playlisty, oczekiwaną odmowę danych prywatnych bez OAuth, Authorization Code z `access_type=offline`, obecność kanału na koncie, utworzenie playlisty `unlisted`, dodanie dwóch pozycji oraz aktualizację zawartości lub kolejności. Używa dokładnego scope'u `https://www.googleapis.com/auth/youtube` opublikowanego przez kontrakt `music-map.platform-access.v1` i nie używa scope'u partnerskiego. Jawnie stwierdza, że service account nie zastępuje zwykłego konta Google z kanałem.

#### 4. Quota, błędy i sprzątanie

**Plik**: `docs/platform-access-readiness.md`

**Cel**: Zapobiec uznaniu pojedynczej odpowiedzi 2xx za pełną gotowość i pozostawianiu testowych danych na kontach.

**Kontrakt**: Dla obu platform runbook klasyfikuje co najmniej brak konfiguracji, 401, 403, 404/zasób niedostępny, 429/quota oraz 5xx jako osobne wyniki bez kopiowania payloadu. Dla YouTube zawiera liczbowy budżet jednostek quota dla playlisty 50-utworowej według kosztów konkretnych metod. Dla Spotify podaje liczbę i sposób grupowania żądań, dokumentuje obserwację `429` oraz `Retry-After` i jawnie stwierdza, że provider nie publikuje stałego limitu liczbowego; nie przedstawia estymacji jako gwarantowanej pojemności. Live test obu platform wykonuje na dwóch pozycjach. Runbook kończy się checklistą ręcznego usunięcia wszystkich utworzonych playlist oraz sprawdzeniem, że żaden sekret ani prywatny URL nie znalazł się w logach lub dowodzie.

#### 5. Szablon współczesnego dowodu

**Plik**: `context/changes/platform-access-readiness/reviews/manual-verification.md`

**Cel**: Przygotować strukturę wyniku, która będzie wypełniana podczas fazy 3, a nie retrospektywnie.

**Kontrakt**: Każdy wiersz zawiera datę UTC, środowisko, lokalny alias projektu/konta, provider, typ tożsamości, scope'y, zdolność, kategorię odpowiedzi, widoczność/quota tam gdzie dotyczy i `PENDING/PASS/BLOCKED`. Plik ma wyraźny zakaz tokenów, sekretów, kodów OAuth, pełnych payloadów, e-maili, platformowych ID, prywatnych linków i PII. Sekcja ograniczeń rozróżnia gotowość deweloperską od produkcyjnej.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełny zestaw testów przechodzi po dodaniu runbooka i szablonu: `composer test`.
- Formatowanie i kontrakt źródła przechodzą: `vendor/bin/pint --test && sh scripts/verify-source-contract --worktree`.
- Skan repozytorium nie znajduje rzeczywistych poświadczeń ani tokenów w nowych artefaktach: istniejąca bramka Gitleaks w CI pozostaje blokująca, a lokalna kontrola korzysta z tego samego narzędzia, jeśli jest dostępne.

#### Weryfikacja ręczna

- Operator przechodzi runbook w trybie tabletop i potwierdza, że każda wymagana zdolność ma jednoznaczny krok, oczekiwany wynik, warunek `BLOCKED` i krok sprzątania.
- Właściciel produktu potwierdza, że macierz pokrywa potrzeby S-02 oraz S-04–S-07 bez implementowania ich zachowania.

**Uwaga implementacyjna**: Po zakończeniu fazy i przejściu automatycznych weryfikacji zatrzymaj się na zatwierdzenie runbooka przed jakimikolwiek zewnętrznymi mutacjami fazy 3.

---

## Faza 3: Walidacja live i przekazanie wyników

### Przegląd

Ta faza wykonuje zatwierdzony runbook na aktywnych projektach i izolowanych kontach. Nie zmienia kodu integracji; jej produktem jest współczesny, sanitizowany dowód oraz jednoznaczna decyzja, czy F-01 rzeczywiście odblokowuje kolejne wycinki.

### Wymagane zmiany

#### 1. Próby kont technicznych

**Plik**: `context/changes/platform-access-readiness/reviews/manual-verification.md`

**Cel**: Potwierdzić, że dedykowane zwykłe konta Spotify oraz Google z kanałem YouTube mogą być właścicielami playlist zarządzanych przez `music-map`.

**Kontrakt**: Dla każdego providera wykonać autoryzację, odczyt, create, zapis dwóch pozycji, update oraz kontrolę widoczności. Próby korzystają z poświadczeń dostarczonych przez zewnętrzny kontrakt runtime i nie dokumentują mechaniki ich przechowywania/rotacji. Poświadczenia ani pełne odpowiedzi nie trafiają do procesu aplikacji, jeśli runbook ich tam nie wymaga.

#### 2. Próby świeżych kont testowych

**Plik**: `context/changes/platform-access-readiness/reviews/manual-verification.md`

**Cel**: Oddzielnie potwierdzić zachowanie przyszłego powiązania konta użytkownika, bez mylenia go z uprzywilejowanym kontem technicznym.

**Kontrakt**: Świeże konto Spotify jest jawnie dodane do dozwolonej grupy Development Mode, a świeże konto Google do test users. Każde ma własną testową playlistę i grant. Wyniki nie mogą używać fallbacku do konta technicznego. Ograniczenie siedmiodniowego refresh tokenu Google Testing zostaje zapisane jako ryzyko, nie jako awaria uzgodnionej bramki deweloperskiej.

#### 3. Oczekiwane odmowy i bezpieczna diagnostyka

**Plik**: `context/changes/platform-access-readiness/reviews/manual-verification.md`

**Cel**: Potwierdzić negatywne granice API i upewnić się, że błędne założenie nie zostanie zinterpretowane jako chwilowa awaria.

**Kontrakt**: Udokumentować co najmniej odmowę elementów cudzej playlisty Spotify, odmowę prywatnej playlisty YouTube bez OAuth oraz odrzucenie brakującego lub niewłaściwego poświadczenia. Zapisać wyłącznie kategorię odpowiedzi i sanitizowaną obserwację. Jeśli sprawdzany jest log aplikacji, stosuje on wzorzec klasy błędu + correlation ID bez wiadomości/payloadu.

#### 4. Sprzątnięcie i decyzja bramki

**Pliki**: `context/changes/platform-access-readiness/reviews/manual-verification.md`, `context/foundation/roadmap.md`

**Cel**: Usunąć wszystkie testowe zasoby i zmienić stan zależności tylko wtedy, gdy cała wymagana macierz jest zielona.

**Kontrakt**: Operator ręcznie usuwa testowe playlisty na obu platformach i zapisuje wynik sprzątania bez ich ID/URL. Każda wymagana zdolność musi mieć `PASS`. Jakiekolwiek `BLOCKED` zatrzymuje zamknięcie F-01 i inicjuje korektę PRD/roadmapy; nie wolno oznaczyć zależnych wycinków jako gotowe ani zastosować atrap/fallbacku. Przy pełnym `PASS` roadmapa może oznaczyć F-01 jako gotowe zgodnie z normalnym cyklem implementacji/archiwizacji.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Po uzupełnieniu sanitizowanego dowodu pełna regresja przechodzi: `composer test`.
- PHP i frontend przechodzą bramki wydania: `vendor/bin/pint --test && npm run build`.
- Kontrakt źródła i audyty zależności przechodzą: `sh scripts/verify-source-contract --worktree && composer audit --locked --no-interaction && npm audit --audit-level=high`.
- `git diff --check` przechodzi, a przegląd zmian nie ujawnia rzeczywistych sekretów, tokenów, kodów OAuth, PII, platformowych ID ani prywatnych URL-i.

#### Weryfikacja ręczna

- Spotify przechodzi macierz na koncie technicznym i świeżym koncie testowym: auth, właściwy odczyt/odmowa, create, dwie pozycje, update i `public=false` z potwierdzonym znaczeniem widoczności.
- YouTube przechodzi macierz na koncie technicznym i świeżym koncie testowym: API-key read, prywatna odmowa bez OAuth, OAuth offline, kanał, create `unlisted`, dwie pozycje i update.
- Wszystkie utworzone playlisty testowe zostają ręcznie usunięte, a sanitizowany zapis zawiera datę, środowisko, obserwacje i wynik bez danych zabronionych.
- Ograniczenia Development/Testing — w tym limity użytkowników i siedmiodniowe tokeny Google Testing — są zaakceptowane jako świadoma granica gotowości deweloperskiej, nie produkcyjnej.

**Uwaga implementacyjna**: Faza kończy się dopiero po ręcznym potwierdzeniu wszystkich pozycji. `BLOCKED` jest wynikiem F-01 wymagającym korekty produktu, a nie podstawą do zaznaczenia kryterium jako wykonane.

---

## Strategia testowania

### Testy jednostkowe

- Sprawdzić kompletność i dokładne mapowanie nazw środowiskowych do `services.spotify` i `services.youtube`.
- Sprawdzić fail-closed placeholdery dla wszystkich sekretów i brak pustych wartości w `.env.example`.
- Utrzymać zakaz dodatkowych sekretów GitHub Actions oraz brak poświadczeń providerów w workflowie.
- Utrzymać brak tokenów streamingowych w `AuthIdentity` i brak migracji bazy w tej zmianie.
- Sprawdzić, że trwały runbook i nowy test są objęte manifestem źródła.

### Testy integracyjne

- Nie wykonywać live integration tests w PHPUnit ani CI.
- Rzeczywiste kontrakty providerów zweryfikować ręcznie według runbooka w fazie 3.
- Pełny `composer test` chroni istniejące uwierzytelnianie, prywatność banku, konfigurację produkcyjną i bezpieczeństwo logowania.

### Kroki testowania ręcznego

1. Zweryfikować aktywne projekty, włączone API, callbacki, tryby aplikacji i minimalne scope'y bez kopiowania poświadczeń do dowodu.
2. Wykonać macierz Spotify na koncie technicznym, a potem niezależnie na świeżym koncie testowym.
3. Wykonać macierz YouTube na koncie technicznym, a potem niezależnie na świeżym koncie testowym.
4. Sprawdzić oczekiwane odmowy i kategorie quota/błędów bez zachowywania pełnych odpowiedzi.
5. Usunąć wszystkie playlisty testowe i potwierdzić sprzątnięcie w sanitizowanym zapisie.
6. Przejrzeć repozytorium i ograniczone logi pod kątem przypadkowego ujawnienia danych.

## Uwagi dotyczące wydajności

Live próby używają dwóch utworów, ponieważ F-01 sprawdza semantykę dostępu, a nie wydajność kompletnego eksportu. Dla playlisty 50-utworowej runbook ma policzyć liczbowy budżet jednostek YouTube, uwzględniając koszt utworzenia playlisty, wyszukania/dopasowania oraz każdej operacji na pozycji. Dla Spotify ma policzyć żądania i sposób ich grupowania oraz opisać zachowanie `429`/`Retry-After`, bez wymyślania niepublikowanego stałego limitu. Pomiar celu około 30 sekund i zachowania po 60 sekundach należy do S-05 oraz właściwych klientów kolejkowych.

Nie dodawać retry ani cache w F-01. Runbook ma rozróżniać 429 od błędów autoryzacji i zatrzymać próbę, zamiast zużywać quota automatycznymi ponowieniami.

## Uwagi dotyczące migracji

Zmiana nie modyfikuje schematu ani danych. Nie wymaga `s-manager schema-release`. Przyszłe modele grantów użytkowników należą do S-04 i muszą być zaprojektowane oddzielnie od `auth_identities`.

Zmiany PRD/roadmapy są trwałą korektą produktu, nie migracją danych. Wycofanie tej decyzji wymaga nowego potwierdzenia aktualnych możliwości Spotify API, a nie prostego przywrócenia wcześniejszego tekstu.

## Referencje

- Wymagania produktu: `context/foundation/prd.md`.
- Zależności i definicja F-01: `context/foundation/roadmap.md`.
- Zasady sekretów i granica zewnętrznego PaaS: `context/foundation/lessons.md`, `context/foundation/infrastructure.md`.
- Wzorzec konfiguracji: `config/services.php:38`, `.env.example:58`.
- Wzorzec bezpiecznego OAuth/logowania: `app/Http/Controllers/Auth/GoogleAuthController.php:23`, `tests/Feature/Auth/GoogleAuthenticationTest.php:145`.
- Wzorzec współczesnego dowodu: `context/archive/2026-09-12-private-account-and-bank/reviews/manual-verification.md:48`.
- Spotify playlist items: https://developer.spotify.com/documentation/web-api/reference/get-playlists-items
- Spotify February 2026 migration guide: https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide
- Spotify authorization and quota modes: https://developer.spotify.com/documentation/web-api/concepts/authorization, https://developer.spotify.com/documentation/web-api/concepts/quota-modes
- Spotify playlist visibility: https://developer.spotify.com/documentation/web-api/concepts/playlists
- YouTube Data API and playlist items: https://developers.google.com/youtube/v3/docs, https://developers.google.com/youtube/v3/docs/playlistItems/list
- YouTube authentication and web-server OAuth: https://developers.google.com/youtube/v3/guides/authentication, https://developers.google.com/identity/protocols/oauth2/web-server
- YouTube playlist visibility and policies: https://developers.google.com/youtube/v3/docs/playlists, https://developers.google.com/youtube/terms/developer-policies
- Google OAuth production readiness: https://developers.google.com/identity/protocols/oauth2/production-readiness/overview

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Urealnienie kontraktu produktu i konfiguracji

#### Automated

- [x] 1.1 Kontrakt konfiguracji platform przechodzi
- [x] 1.2 Kontrakt źródła obejmuje nowe trwałe pliki
- [x] 1.3 Dokumenty fundamentu nie obiecują niewykonalnego importu ani prywatności Spotify

#### Manual

- [ ] 1.4 Właściciel produktu akceptuje skorygowany kontrakt Spotify i YouTube
- [ ] 1.5 Operator potwierdza publiczny kontrakt nazw runtime bez ujawniania wartości

### Phase 2: Runbook i macierz akceptacyjna

#### Automated

- [x] 2.1 Pełny zestaw testów przechodzi po dodaniu runbooka i szablonu
- [x] 2.2 Formatowanie i kontrakt źródła przechodzą
- [x] 2.3 Skan repozytorium nie znajduje rzeczywistych poświadczeń ani tokenów

#### Manual

- [x] 2.4 Operator zatwierdza kompletny runbook w trybie tabletop
- [ ] 2.5 Właściciel produktu potwierdza pokrycie potrzeb zależnych wycinków

### Phase 3: Walidacja live i przekazanie wyników

#### Automated

- [ ] 3.1 Pełna regresja przechodzi po uzupełnieniu dowodu
- [ ] 3.2 PHP i frontend przechodzą bramki wydania
- [ ] 3.3 Kontrakt źródła i audyty zależności przechodzą
- [ ] 3.4 Diff i przegląd zmian nie ujawniają danych zabronionych

#### Manual

- [ ] 3.5 Spotify przechodzi macierz na koncie technicznym i testowym
- [ ] 3.6 YouTube przechodzi macierz na koncie technicznym i testowym
- [ ] 3.7 Testowe playlisty są usunięte, a sanitizowany dowód kompletny
- [ ] 3.8 Granice Development i Testing są jawnie zaakceptowane
