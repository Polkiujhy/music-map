# Gotowość dostępu do Spotify i YouTube — plan implementacji

> **Plan zastąpiony:** nie implementować lokalnego `platforms:authorize` ani
> opisanego niżej alternatywnego kontraktu readiness. Aktualny plan pełnej
> gotowości jest własnością Managera:
> `/srv/manager/context/changes/music-map-platform-access-readiness/plan.md`.

## Przegląd

Zmiana dostarcza powtarzalny i bezsekretny sposób potwierdzenia, że testowe aplikacje oraz dedykowane konta techniczne `music-map` mają minimalne uprawnienia potrzebne do przyszłego importu, linkowania i eksportu playlist przez Spotify Web API oraz YouTube Data API. Fundament definiuje też publiczny kontrakt konfiguracji produkcyjnej, ale zgodnie z decyzją planistyczną nie wykonuje live smoke przeciwko produkcyjnym poświadczeniom zarządzanym przez Manager.

Plan koryguje założenia produktu ujawnione przez aktualne API: Spotify pozwala odczytać elementy playlisty tylko autoryzowanemu właścicielowi lub współpracownikowi, YouTube wymaga szerokiego scope `youtube.force-ssl` do zapisu, a domyślna kwota YouTube uzasadnia ograniczenie MVP do playlist zawierających 20 utworów i pięciu rozpoczętych operacji zapisu dziennie.

## Analiza stanu obecnego

Repozytorium nie zawiera jeszcze kodu, konfiguracji, tras, migracji ani testów integracji Spotify lub YouTube. `config/services.php` zna wyłącznie klienta Google używanego do logowania, a `.env.example` nie definiuje żadnych poświadczeń platform streamingowych. Istniejąca integracja Google jest stanowa, waliduje odpowiedź dostawcy i loguje wyłącznie klasę wyjątku oraz correlation ID, co stanowi wzorzec dla bezpiecznych diagnostyk platform.

Tabela `auth_identities` przechowuje wyłącznie stabilne identyfikatory metod logowania i celowo nie zawiera tokenów. Tokeny testowych kont technicznych będą więc pochodzić z lokalnego, nieśledzonego `.env`, produkcyjne wartości będą dostarczane przez zewnętrzny PaaS według tych samych symbolicznych nazw, a zaszyfrowany model poświadczeń kont użytkowników pozostaje odpowiedzialnością S-04.

Planowanie zaktualizowało trwały kontrakt produktu w `context/foundation/prd.md`, `context/foundation/roadmap.md` oraz wspierające decyzje w `context/foundation/shape-notes.md`: limit playlist wynosi 20 utworów, zapis YouTube ma globalny budżet pięciu rozpoczętych operacji na dzień kwoty API, a import Spotify wymaga powiązanego konta będącego właścicielem lub współpracownikiem.

### Kluczowe odkrycia

- F-01 odblokowuje S-02 oraz S-04–S-07, więc wynik musi rozróżniać gotowość odczytu i zapisu per platforma zamiast zwracać jeden ogólny status (`context/foundation/roadmap.md:42`, `context/foundation/roadmap.md:79`).
- Konfiguracja usług zewnętrznych korzysta z `config/services.php`, a bezpieczne przykłady runtime używają sentinela `__REQUIRED_RUNTIME_SECRET__` (`config/services.php:38`, `.env.example:3`).
- CI kopiuje `.env.example` i uruchamia testy bez sekretów providera, dlatego testy automatyczne muszą korzystać z atrap HTTP, a wywołania live pozostają jawnie lokalne (`.github/workflows/ci.yml:55`).
- `auth_identities` nie ma pól tokenów, a wcześniejszy plan jawnie oddzielił logowanie Google od przyszłych integracji streamingowych (`app/Models/AuthIdentity.php:11`, `context/archive/2026-09-12-private-account-and-bank/plan.md:44`).
- Callback Google zapewnia wzorzec neutralnego błędu i sanitowanego telemetry bez URI, payloadu, tokenów ani danych osobowych (`app/Http/Controllers/Auth/GoogleAuthController.php:54`).
- Nginx aplikacji nie zapisuje pełnych URI, ponieważ parametry callbacków mogą zawierać dane uwierzytelniające; fundament nie może osłabić tego kontraktu (`docker/nginx/default.conf:9`, `tests/Unit/ProductionInfrastructureTest.php:227`).
- Spotify Client Credentials nie daje dostępu do zasobów użytkownika. Zarówno konto techniczne, jak i późniejsze konto użytkownika wymagają Authorization Code oraz refresh tokenu.
- YouTube nie obsługuje service accounts dla zasobów kanału. Konto techniczne musi być dedykowanym kontem Google/YouTube lub Brand Account autoryzowanym przez zwykły server-side OAuth.

## Pożądany stan końcowy

- `.env.example` i `config/services.php` definiują osobne od logowania Google, niesekretne nazwy konfiguracji Spotify oraz YouTube, minimalne scope i dane potrzebne do lokalnej próby gotowości.
- Testowe konto Spotify ma scope `playlist-read-private`, `playlist-read-collaborative` i `playlist-modify-private`; YouTube używa API key do publicznego odczytu oraz OAuth `youtube.force-ssl` dopiero do zapisu.
- Komenda Artisan zwraca zawsze pełną macierz `config/auth/read/write/cleanup`, ale wymagalność zależy od platformy i trybu: domyślnie Spotify wymaga `config/auth/read`, YouTube tylko `config/read`, a niewykonywane zdolności mają `SKIP`; po jawnym `--write` wszystkie zdolności wybranego providera są wymagane.
- Brak, pusta wartość albo sentinel konfiguracji kończy się bez wywołania sieci. Błąd wymaganej zdolności daje niezerowy exit code, bez ujawniania sekretów, surowych odpowiedzi, pełnych URL-i, identyfikatorów zasobów lub danych konta.
- Write smoke Spotify modyfikuje dedykowaną, wielorazową prywatną playlistę testową i w bloku `finally` przywraca jej pierwotne szczegóły oraz elementy; utworzenie prywatnej playlisty przez API jest osobnym, jednorazowym krokiem ręcznym. Write smoke YouTube tworzy wyraźnie jednorazową playlistę `unlisted`, modyfikuje ją i usuwa w `finally`. Nieudane przywrócenie albo usunięcie jest osobnym wynikiem blokującym.
- Testy jednostkowe i funkcjonalne przechodzą bez połączeń z providerami, a lokalne próby live na osobnych projektach testowych są opisane w redagowanym `verification.md`.
- Oddzielna, wyłącznie lokalna komenda bootstrapu OAuth przeprowadza Authorization Code z jednorazowym callbackiem loopback, `state` i PKCE, zapisując refresh token oraz datę autoryzacji bezpośrednio do ignorowanego `.env` bez ujawniania ich w argv, konsoli ani logach.
- Produkcja ma zdefiniowany kontrakt symbolicznych nazw i bezpiecznego sygnalizowania wygaśnięcia lub wymaganej rotacji poświadczeń, lecz F-01 nie twierdzi, że produkcyjne wartości zostały sprawdzone live ani że PaaS udostępnia już zapisywalny kanał rotacji; ten kanał pozostaje prerequisite przyszłego runtime zapisującego.

## Czego NIE robimy

- Nie dodajemy UI, tras aplikacji ani callbacków do linkowania kont użytkowników Spotify/YouTube. Dozwolony jest wyłącznie krótkotrwały callback loopback lokalnej komendy operatorskiej, niedostępny w runtime webowym i nieużywany przez konta użytkowników.
- Nie tworzymy migracji ani modelu zaszyfrowanych tokenów użytkowników; należy to do S-04.
- Nie implementujemy importu, dopasowania, eksportu, synchronizacji ani dziennego licznika kwoty w przepływie użytkownika.
- Nie wykonujemy live smoke na produkcyjnych poświadczeniach ani produkcyjnych kontach technicznych.
- Nie modyfikujemy `/srv/manager` i nie opisujemy hostowych ścieżek, mountów, uprawnień, transportu sekretów lub innych internalsów PaaS.
- Nie używamy scrapingu, nieoficjalnych endpointów Spotify ani service accounts YouTube.
- Nie dodajemy publicznych scope zapisu Spotify ani automatycznego publikowania playlist na profilach.
- Nie zapisujemy tokenów, nazw kont, identyfikatorów testowych playlist ani surowych odpowiedzi API w repozytorium lub logach.
- Nie automatyzujemy zwiększenia kwoty YouTube ani procesu weryfikacji aplikacji przez Google.

## Podejście do implementacji

Powstanie pojedyncza komenda Artisan oparta na dwóch cienkich, niezależnych probe'ach platform. Wspólny kontrakt wyniku opisze zdolności i stabilne kategorie błędów, natomiast każdy probe zachowa właściwy model dostępu providera: Spotify odświeża OAuth konta technicznego przed odczytem lub zapisem, a YouTube rozdziela publiczny odczyt przez API key od zapisu z OAuth.

Osobna komenda `platforms:authorize` będzie lokalnym bootstrapem poświadczeń technicznych, a nie częścią zwykłego readiness ani przyszłego linkowania kont użytkowników. Uruchomi przeglądarkę bez wypisywania providerowego authorization URL, przyjmie dokładnie jeden callback na `127.0.0.1`, zweryfikuje `state` i PKCE, wymieni kod po stronie serwera oraz zapisze refresh token i datę autoryzacji przez wąski lokalny magazyn modyfikujący wyłącznie odpowiednie klucze ignorowanego `.env`. Komenda odmówi działania poza środowiskiem `local`. Ten sam magazyn zapisze nowy refresh token zwrócony podczas lokalnego odświeżenia Spotify; brak bezpiecznego zapisywalnego kontraktu rotacji w produkcyjnym PaaS pozostaje jawnym prerequisite przyszłego runtime, a nie ukrytą odpowiedzialnością F-01.

Domyślne uruchomienie będzie niedestrukcyjne: dla Spotify sprawdzi konfigurację, odświeżenie dostępu i odczyt wskazanego fixture, a dla YouTube konfigurację i publiczny odczyt przez API key bez OAuth. Flaga `--write` jawnie uruchomi update/item-write/restore na dedykowanej prywatnej playliście testowej Spotify oraz OAuth i create/update/item-write/delete na koncie technicznym YouTube. Komenda będzie oferować format czytelny dla człowieka oraz wersjonowany JSON, aby kolejne wycinki mogły jednoznacznie ustalić, która zdolność jest dostępna.

Wszystkie testy automatyczne użyją atrap Laravel HTTP client i nie będą wymagały sekretów ani sieci. Prawdziwe próby zostaną wykonane lokalnie na oddzielnych projektach i kontach testowych, a ich redagowany wynik zostanie zapisany jako współczesny dowód. Produkcja konsumuje tylko ten sam publiczny kontrakt nazw i semantyki konfiguracji; sposób dostarczenia wartości pozostaje własnością Managera.

## Krytyczne szczegóły implementacji

### Czas i cykl życia

Spotify access token jest krótkotrwały, a refresh token ma obecnie sześciomiesięczny limit od pierwotnej autoryzacji i podczas odświeżenia może zostać zastąpiony. F-01 musi rejestrować bezpieczną datę autoryzacji, odrzucać `invalid_grant` bez ponawiania oraz dokumentować konieczność ponownej autoryzacji; nie może udawać trwałej gotowości na podstawie pojedynczego access tokenu. Spotify Development Mode wymaga aktywnego Premium właściciela aplikacji oraz umieszczenia konta technicznego na allowliście; są to jawne wymagania operatorskie, ponieważ probe nie może ich niezawodnie odróżnić od innych odpowiedzi `403`.

YouTube zapisuje datę autoryzacji oraz status publikacji consent screen. Dla zewnętrznego projektu o statusie `testing` refresh token ze scope YouTube wygasa po siedmiu dniach; po przekroczeniu tego wieku readiness zwraca `reauthorization_required` bez próby sieciowej. Dla statusu `production` albo `internal` data pozostaje diagnostyczna, ponieważ token nadal może zostać unieważniony z innych powodów.

### Debugowanie i obserwowalność

Wynik może zawierać wyłącznie provider, nazwę zdolności, `PASS`/`FAIL`/`SKIP`, stabilną kategorię błędu, bezpieczny status HTTP oraz correlation ID. Surowe body, nagłówki autoryzacji, request URL, tokeny, nazwy kont, adresy e-mail i identyfikatory playlist są zabronione zarówno w konsoli, jak i w logach.

## Phase 1: Kontrakt platform i bezpieczna konfiguracja

### Przegląd

Faza utrwala minimalne scope, rozdział tożsamości i symboliczny kontrakt konfiguracji wspólny dla lokalnych projektów testowych i późniejszego runtime produkcyjnego.

### Wymagane zmiany

#### 1. Konfiguracja środowiska

**Pliki**: `.env.example`, `config/services.php`

**Cel**: Zdefiniować osobne od logowania Google konfiguracje Spotify i YouTube oraz jednoznacznie rozdzielić wartości publiczne, sekrety aplikacji, refresh tokeny kont technicznych i niesekretne fixture readiness.

**Kontrakt**: Spotify korzysta z `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, `SPOTIFY_REDIRECT_URI`, `SPOTIFY_TECHNICAL_REFRESH_TOKEN`, `SPOTIFY_TECHNICAL_AUTHORIZED_AT`, `SPOTIFY_READINESS_PLAYLIST_ID`, osobnego `SPOTIFY_READINESS_WRITE_PLAYLIST_ID` wskazującego dedykowaną, wielorazową prywatną playlistę testową oraz niesekretnego `SPOTIFY_READINESS_ITEM_URI` wskazującego kontrolny element możliwy do zapisania. YouTube korzysta z `YOUTUBE_API_KEY`, `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`, `YOUTUBE_REDIRECT_URI`, `YOUTUBE_TECHNICAL_REFRESH_TOKEN`, `YOUTUBE_TECHNICAL_AUTHORIZED_AT`, niesekretnego `YOUTUBE_OAUTH_PUBLISHING_STATUS` o wartości `testing`, `production` albo `internal`, `YOUTUBE_READINESS_PLAYLIST_ID` i niesekretnego `YOUTUBE_READINESS_VIDEO_ID` wskazującego kontrolny publiczny film możliwy do dodania do playlisty. Sekrety mają sentinel `__REQUIRED_RUNTIME_SECRET__`; wszystkie identyfikatory fixture pozostają wyłącznie w lokalnym `.env` lub zewnętrznej konfiguracji runtime i nigdy nie są emitowane. Wartości środowiskowe nie są sufiksowane, więc lokalny `.env` wskazuje projekty testowe, a produkcja dostarcza własne wartości pod tymi samymi nazwami. Scope są stałym, przeglądalnym kontraktem kodu, nie dowolną wartością env.

#### 2. Wspólny kontrakt wyniku readiness

**Pliki**: `app/Services/PlatformAccess/ReadinessResult.php`, `app/Services/PlatformAccess/ReadinessCapability.php`, `app/Services/PlatformAccess/ReadinessError.php`

**Cel**: Zapewnić jeden typowany format zdolności i błędów, który nie zależy od tekstu odpowiedzi Spotify lub Google i może być bezpiecznie renderowany w konsoli oraz JSON.

**Kontrakt**: Zdolności to `config`, `auth`, `read`, `write` i `cleanup`; statusy to `PASS`, `FAIL` i `SKIP`. Kategorie obejmują co najmniej brak/placeholder konfiguracji, nieprawidłowy fixture, wymaganie ponownej autoryzacji, odmowę autoryzacji, niespełnione wymaganie konta, brak zakresu, brak dostępu do zasobu, limit/kwotę, niedostępność providera, nieprawidłową odpowiedź i nieudany cleanup. Format JSON ma jawne `schema_version: 1`, czas sprawdzenia, tryb, wynik ogólny oraz listę wyników per provider bez pól na surowe dane.

Pełna macierz jest zawsze obecna i ma następującą wymagalność:

| Provider i tryb | `config` | `auth` | `read` | `write` | `cleanup` |
| --- | --- | --- | --- | --- | --- |
| Spotify, domyślny | wymagane | wymagane | wymagane | `SKIP` | `SKIP` |
| YouTube, domyślny | wymagane | `SKIP` | wymagane | `SKIP` | `SKIP` |
| Spotify, `--write` | wymagane | wymagane | wymagane | wymagane | wymagane |
| YouTube, `--write` | wymagane | wymagane | wymagane | wymagane | wymagane |

`SKIP` nie obniża wyniku ogólnego. Przy `--provider` wyniki drugiego providera nie są emitowane. Brakujące ustawienie potrzebne wyłącznie do zapisu nie blokuje domyślnego odczytu, ale blokuje `--write` przed jakimkolwiek requestem.

#### 3. Dokumentacja kontraktu i zabezpieczenia źródła

**Pliki**: `README.md`, `scripts/verify-source-contract`, `tests/Unit/ProductionInfrastructureTest.php`, `tests/Unit/Services/PlatformAccess/PlatformAccessConfigurationTest.php`

**Cel**: Opisać bezpieczne przygotowanie lokalnych projektów testowych i chronić komplet symbolicznych placeholderów bez wprowadzania sekretów do CI.

**Kontrakt**: README rozróżnia klienta Google do logowania od klienta YouTube do integracji, wyjaśnia dedykowane konta techniczne, minimalne scope, lokalny `.env` i brak live testów w CI. Dokumentuje siedmiodniowy cykl refresh tokenu YouTube przy zewnętrznym consent screen w statusie `testing` oraz wymagania Spotify Development Mode: Premium właściciela aplikacji i allowlistę konta technicznego. Source contract dodaje wszystkie stabilne pliki readiness z `app/Console/Commands/`, `app/Contracts/PlatformAccess/`, `app/Services/PlatformAccess/`, `tests/Feature/Console/` i `tests/Unit/Services/PlatformAccess/`, ale zgodnie z kontraktem cyklu życia nie dodaje `context/changes/platform-access-readiness/verification.md` ani innych artefaktów `context/changes/*` do `required_paths`. Test infrastrukturalny potwierdza wymagane placeholdery, brak nowych sekretów workflow i zachowanie istniejącego zakazu logowania pełnych URI.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Test konfiguracji potwierdza komplet symbolicznych ustawień i minimalnych scope bez wypisywania wartości poufnych: `php artisan test tests/Unit/Services/PlatformAccess/PlatformAccessConfigurationTest.php`.
- Test kontraktu środowiska odrzuca puste wartości i sentinel jako gotowe poświadczenia, nie wymagając live API: `php artisan test tests/Unit/ProductionInfrastructureTest.php`.
- Test cyklu poświadczeń odrzuca bez sieci przeterminowaną siedmiodniową autoryzację YouTube w statusie `testing` i zachowuje odrębne reguły dla `production` oraz `internal`: `php artisan test tests/Unit/Services/PlatformAccess/PlatformAccessConfigurationTest.php`.
- Testy typów wyniku potwierdzają stałą macierz, wersję JSON i brak pól zdolnych przenosić surowe request/response lub tokeny: `php artisan test tests/Unit/Services/PlatformAccess`.
- Kontrakt źródła przechodzi po dodaniu dokładnie stabilnych plików readiness, a test parsera potwierdza wykluczenie artefaktów cyklu życia zmian: `sh scripts/verify-source-contract --worktree` oraz `php artisan test tests/Unit/SourceContractTest.php`.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Przegląd `.env.example`, konfiguracji i README potwierdza oddzielenie Google login, Spotify integration i YouTube integration oraz brak realnych danych kont.
- Przegląd scope potwierdza dokładnie `playlist-read-private`, `playlist-read-collaborative`, `playlist-modify-private` dla Spotify oraz publiczny odczyt API key i `youtube.force-ssl` do zapisu YouTube.
- Operator potwierdza, że lokalne wartości wskazują osobne projekty testowe, a repo opisuje produkcję tylko przez nazwy i semantykę publicznego kontraktu PaaS.
- Operator potwierdza status consent screen i wiek autoryzacji YouTube oraz aktywne Premium właściciela i obecność konta technicznego na allowliście Spotify Development Mode.

**Uwaga implementacyjna**: Po zakończeniu fazy zatrzymaj się na ręczne potwierdzenie granicy sekretów i scope przed dodaniem kodu wywołującego zewnętrzne API.

---

## Phase 2: Powtarzalna komenda readiness

### Przegląd

Faza dostarcza testowalny bez sieci mechanizm diagnostyczny oraz jawnie opt-in próbę zapisu z obowiązkowym cleanup.

### Wymagane zmiany

#### 1. Lokalny bootstrap OAuth i magazyn technicznych poświadczeń

**Pliki**: `app/Console/Commands/AuthorizePlatformAccess.php`, `app/Services/PlatformAccess/LocalEnvironmentCredentialStore.php`, `app/Services/PlatformAccess/SpotifyAuthorizationBootstrap.php`, `app/Services/PlatformAccess/YouTubeAuthorizationBootstrap.php`

**Cel**: Umożliwić powtarzalne uzyskanie poświadczeń kont technicznych oraz bezpieczne zachowanie rotacji Spotify bez dodawania tras aplikacji i bez kopiowania tokenów przez konsolę.

**Kontrakt**: `php artisan platforms:authorize --provider=spotify|youtube` działa wyłącznie przy `APP_ENV=local`, tworzy krótkotrwały listener na `127.0.0.1`, otwiera przeglądarkę bez renderowania authorization URL, używa losowego `state` i PKCE, przyjmuje dokładnie jeden callback i wymienia kod po stronie serwera. Zapisuje wyłącznie właściwy refresh token oraz datę autoryzacji do ignorowanego `.env`, zachowując pozostałą zawartość; token, kod, verifier, pełny callback URI i odpowiedzi providera nigdy nie trafiają do argv, outputu ani logów. Odmowa, timeout, niedopasowany `state`, brak refresh tokenu i błąd zapisu kończą się neutralnym komunikatem oraz niezerowym kodem bez częściowej aktualizacji `.env`. Lokalny probe Spotify używa tego samego magazynu, gdy odpowiedź refresh zawiera nowy refresh token, ale nie zmienia daty pierwotnej autoryzacji.

#### 2. Probe Spotify

**Pliki**: `app/Services/PlatformAccess/SpotifyReadinessProbe.php`, `app/Contracts/PlatformAccess/PlatformReadinessProbe.php`

**Cel**: Sprawdzić odświeżenie dostępu, odczyt playlisty właściciela lub współpracownika oraz prywatny zapis dedykowanego konta bez implementowania domenowego importu lub eksportu.

**Kontrakt**: Probe wymienia refresh token na access token, klasyfikuje `invalid_grant` jako wymagający ponownej autoryzacji i nigdy nie ponawia go automatycznie. Odczyt potwierdza dostęp do elementów skonfigurowanej playlisty. Write smoke odczytuje i zapamiętuje szczegóły oraz elementy dedykowanej prywatnej playlisty `SPOTIFY_READINESS_WRITE_PLAYLIST_ID`, aktualizuje ją, zapisuje dokładnie `SPOTIFY_READINESS_ITEM_URI`, a w `finally` przywraca dokładnie zapamiętany stan. Brak, sentinel albo składniowo niepoprawny URI kończy się przed siecią; odrzucenie prawidłowo ukształtowanego elementu przez Spotify daje bezpieczną kategorię `invalid_fixture`. Probe nie tworzy ani nie próbuje usuwać playlisty przez API. Nieudane przywrócenie daje blokujący wynik `cleanup`; częściowe przywrócenie pozostaje błędem wymagającym ręcznej kontroli. Zwrócony nowy refresh token nie trafia do outputu; w środowisku lokalnym zostaje bezpiecznie zapisany przez `LocalEnvironmentCredentialStore`, a błąd zapisu blokuje gotowość. Jednorazowe utworzenie prywatnej playlisty przez aktualny endpoint Spotify jest osobnym ręcznym testem zapisanym w `verification.md`.

#### 3. Probe YouTube

**Plik**: `app/Services/PlatformAccess/YouTubeReadinessProbe.php`

**Cel**: Niezależnie potwierdzić tani publiczny odczyt przez API key oraz zapis playlisty przez OAuth zwykłego konta YouTube.

**Kontrakt**: Read używa `playlists.list` i `playlistItems.list` z paginacją do limitu 20 elementów. Auth/write odświeża token konta technicznego ze scope `youtube.force-ssl`; service account jest niedozwolony. Przed odświeżeniem konfiguracja o statusie `testing` sprawdza siedmiodniowy wiek `YOUTUBE_TECHNICAL_AUTHORIZED_AT` i po jego przekroczeniu zwraca `reauthorization_required` bez requestu. Write smoke tworzy playlistę `unlisted`, wykonuje read-modify-write bez zerowania pominiętych pól, dodaje dokładnie `YOUTUBE_READINESS_VIDEO_ID` i usuwa playlistę w `finally`. Brak, sentinel albo składniowo niepoprawny ID kończy się przed siecią; odrzucenie prawidłowo ukształtowanego filmu przez YouTube daje bezpieczną kategorię `invalid_fixture`. Błędy `quotaExceeded`/HTTP 403 są odróżnione od braku uprawnień.

#### 4. Komenda i format wyjścia

**Plik**: `app/Console/Commands/CheckPlatformAccess.php`

**Cel**: Udostępnić jeden bezpieczny entrypoint dla lokalnej weryfikacji obu platform i umożliwić precyzyjne blokowanie kolejnych wycinków.

**Kontrakt**: `php artisan platforms:readiness` wykonuje niedestrukcyjną macierz domyślną; `--write` włącza pełną macierz zapisu, `--provider=spotify|youtube` zawęża próbę, a `--format=json` emituje schema v1. Kod `0` oznacza wszystkie wymagane zdolności `PASS`, `1` niegotowość providera, `2` niebezpieczną lub brakującą konfigurację, a `3` nieudany cleanup. Gdy wystąpi kilka klas błędów, obowiązuje deterministyczna precedencja `3` cleanup > `2` konfiguracja > `1` provider > `0` sukces. Komenda nie przyjmuje sekretów w argumentach, nie wypisuje wyjątków ani surowych odpowiedzi i nie oferuje flagi produkcyjnego fallbacku.

#### 5. Testy zachowania i poufności

**Pliki**: `tests/Feature/Console/CheckPlatformAccessTest.php`, `tests/Feature/Console/AuthorizePlatformAccessTest.php`, `tests/Unit/Services/PlatformAccess/SpotifyReadinessProbeTest.php`, `tests/Unit/Services/PlatformAccess/YouTubeReadinessProbeTest.php`, `tests/Unit/Services/PlatformAccess/LocalEnvironmentCredentialStoreTest.php`

**Cel**: Utrwalić wszystkie ścieżki powodzenia, odmowy i cleanup bez prawdziwych połączeń sieciowych.

**Kontrakt**: Laravel HTTP fake pokrywa pełną macierz obu providerów, timeout, 401, 403, 429/`quotaExceeded`, błędne body, brak scope, `invalid_grant`, brakujące/sentinelowe/nieprawidłowe elementy fixture, przeterminowaną autoryzację YouTube w statusie `testing`, rotację refresh tokenu i awarię cleanup. Testy dowodzą, że nieprawidłowa konfiguracja fixture i przeterminowany token są odrzucane bez sieci, domyślny tryb nie wykonuje mutacji, `--write` zawsze próbuje cleanup po częściowym sukcesie, exit codes są stabilne, a output/logi nie zawierają wstrzykniętych tokenów, sekretów, nagłówków, URL-i, nazw kont, fixture ID ani surowych body.

Test bootstrapu używa atrap przeglądarki, listenera loopback i odpowiedzi token endpointu. Potwierdza local-only, jednorazowy callback, `state`, PKCE, timeout i odmowę oraz zapis do tymczasowego pliku `.env` bez ujawnienia tokenu, kodu, verifiera lub callback URI. Test magazynu potwierdza zachowanie pozostałych kluczy, brak częściowego zapisu oraz zapis rotacji Spotify bez zmiany daty pierwotnej autoryzacji.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Test komendy potwierdza macierz human/JSON, filtrowanie providerów i exit codes: `php artisan test tests/Feature/Console/CheckPlatformAccessTest.php`.
- Test Spotify przechodzi dla auth/read/write/cleanup oraz wszystkich sklasyfikowanych odmów: `php artisan test tests/Unit/Services/PlatformAccess/SpotifyReadinessProbeTest.php`.
- Test YouTube przechodzi dla API-key read, OAuth write, paginacji do 20 elementów, kwoty i cleanup: `php artisan test tests/Unit/Services/PlatformAccess/YouTubeReadinessProbeTest.php`.
- Test lokalnego bootstrapu i magazynu poświadczeń potwierdza state/PKCE, local-only, jednorazowy callback, bezpieczny zapis i rotację Spotify: `php artisan test tests/Feature/Console/AuthorizePlatformAccessTest.php tests/Unit/Services/PlatformAccess/LocalEnvironmentCredentialStoreTest.php`.
- Test poufności potwierdza nieobecność wszystkich wstrzykniętych sekretów i identyfikatorów w output/logach także przy wyjątkach.
- Pełny zestaw PHPUnit przechodzi bez sieci i poświadczeń providerów: `composer test`.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Przegląd potwierdza, że probe'y są wyłącznie diagnostyczne i nie wprowadzają modeli, tras ani logiki domenowej późniejszych wycinków.
- Uruchomienie bez `--write` nie tworzy, nie modyfikuje ani nie usuwa żadnego zasobu platformy.
- Playlista Spotify jest jednoznacznie oznaczonym, dedykowanym fixture wielorazowym, a po próbie odzyskuje pierwotne szczegóły i elementy; playlista YouTube jest jednoznacznie jednorazowa. Awaria przywrócenia albo usunięcia jest widoczna jako wynik blokujący.
- Operator potwierdza, że bootstrap otwiera zgodę właściwego projektu testowego, callback loopback nie jest trasą aplikacji, a poświadczenia są zapisane bez ręcznego kopiowania lub wyświetlenia.

**Uwaga implementacyjna**: Po automatycznej weryfikacji zatrzymaj się przed live `--write`; operator musi potwierdzić, że aktywne środowisko wskazuje wyłącznie dedykowane projekty i konta testowe.

---

## Phase 3: Lokalna weryfikacja live i trwały dowód

### Przegląd

Faza przeprowadza kontrolowane próby na rzeczywistych testowych API, sprawdza cykl odświeżania i unieważniania oraz zapisuje redagowany dowód gotowości bez twierdzeń o produkcyjnym smoke.

### Wymagane zmiany

#### 1. Instrukcja prób live i unieważniania

**Plik**: `README.md`

**Cel**: Zapewnić powtarzalną kolejność lokalnych prób i bezpieczne odtworzenie dostępu po teście unieważnienia.

**Kontrakt**: Runbook zaczyna od config-only review i lokalnego `platforms:authorize` dla brakujących lub unieważnionych poświadczeń, uruchamia niedestrukcyjny readiness per provider, potem jawny `--write`, a na końcu osobno testuje unieważnienie. YouTube używa oficjalnego revoke i ponownej autoryzacji przez bootstrap; Spotify usuwa lokalne wartości tokenów, wymaga ręcznego cofnięcia dostępu w ustawieniach Apps i ponownej autoryzacji przez bootstrap. Żaden krok nie podaje tokenu w argv, nie kopiuje go do raportu i nie zmienia produkcyjnego środowiska.

#### 2. Redagowany zapis weryfikacji

**Plik**: `context/changes/platform-access-readiness/verification.md`

**Cel**: Utrwalić współczesny dowód, na którym bez ponownego zgadywania oprą się S-02 oraz S-04–S-07.

**Kontrakt**: Dokument zawiera datę, oznaczenie lokalnego środowiska testowego, wersję schematu komendy, użyte minimalne scope, niesekretny status consent screen YouTube, potwierdzenie wymagań konta Spotify Development Mode oraz macierz `config/auth/read/write/cleanup/revoke` per provider, status i bezpieczną kategorię błędu. Nie zawiera nazw/e-maili kont, client IDs, tokenów, fixture IDs, URL-i zasobów ani surowych odpowiedzi. Jawnie stwierdza, że produkcyjne poświadczenia nie były testowane live.

#### 3. Zamknięcie kontraktu MVP

**Pliki**: `context/foundation/prd.md`, `context/foundation/roadmap.md`, `context/foundation/shape-notes.md`

**Cel**: Zweryfikować, że dalsze wycinki konsumują ustalone podczas planowania ograniczenia platform, pojemności i kwoty bez powrotu do niewykonalnej obietnicy publicznego importu Spotify.

**Kontrakt**: FR-004 wymaga konta właściciela/współpracownika dla Spotify, NFR-001/NFR-002 używają 20 utworów, NFR-006 utrwala pięć rozpoczętych zapisów YouTube na dzień kwoty API, roadmapa opisuje F-01 jako lokalny dowód testowy plus produkcyjny kontrakt konfiguracji, a wspierające decyzje w `shape-notes.md` pozostają zgodne z tymi samymi wartościami. Implementacja F-01 nie dodaje jeszcze runtime enforcement NFR-006.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełne bramki repo przechodzą: `composer test`, `vendor/bin/pint --test`, `npm run build` oraz `sh scripts/verify-source-contract --worktree`.
- Statyczna kontrola potwierdza spójne wartości 20 utworów i pięciu zapisów YouTube w PRD, roadmapie, `shape-notes.md` oraz testach kontraktu.
- `git diff --check` nie zgłasza błędów whitespace, a `git diff` nie zawiera wartości lokalnego `.env` ani artefaktów runtime.

#### Weryfikacja ręczna

- Spotify potwierdza auth, odczyt playlisty właściciela lub współpracownika oraz powtarzalny update/item-write/restore dedykowanej prywatnej playlisty testowej; osobny jednorazowy test ręczny potwierdza utworzenie prywatnej playlisty przez aktualny endpoint API.
- YouTube potwierdza publiczny odczyt przez API key, OAuth `youtube.force-ssl`, create/update/item-write playlisty `unlisted` oraz cleanup na dedykowanym koncie testowym.
- Domyślna komenda i oba uruchomienia `--write` kończą się kodem `0` w lokalnym środowisku testowym; redagowany JSON nie zawiera danych poufnych.
- Test unieważnienia dowodzi programistycznego revoke i reautoryzacji YouTube oraz lokalnego usunięcia tokenów, ręcznego cofnięcia zgody i reautoryzacji Spotify.
- `verification.md` został zapisany w chwili prób, zawiera wszystkie wyniki i ograniczenie braku produkcyjnego smoke, ale nie ujawnia poświadczeń, PII ani identyfikatorów zasobów.
- Operator potwierdza, że osobne produkcyjne projekty mogą dostarczyć te same symboliczne ustawienia przez Manager, bez kopiowania mechaniki PaaS do repozytorium.

**Uwaga implementacyjna**: Faza i całe F-01 kończą się dopiero po ręcznej akceptacji redagowanego `verification.md`. Produkcyjne live smoke pozostaje jawnym prerequisite pierwszego wdrożenia S-04/S-06/S-07, a nie dowodem tej zmiany.

---

## Strategia testowania

### Testy jednostkowe

- Mapowanie odpowiedzi i wyjątków obu providerów na stabilne statusy oraz kategorie bez surowego payloadu.
- Walidacja brakujących, pustych i sentinelowych wartości konfiguracji przed siecią.
- Odświeżanie access tokenu, `invalid_grant`, sygnał rotacji Spotify i odmowa service account YouTube.
- Paginacja odczytu do 20 elementów i odróżnienie limitu/kwoty od braku uprawnień.
- Obowiązkowy cleanup po pełnym i częściowym write smoke.

### Testy integracyjne

- Komenda Artisan składa oba probe'y, renderuje tabelę/JSON schema v1 i zwraca właściwy exit code.
- Tryb domyślny nie wysyła requestów mutujących, a `--write` uruchamia je tylko dla wybranego providera.
- Laravel HTTP fake potwierdza dokładne hosty, metody i minimalne scope bez wykonywania sieci.
- Output i logi pozostają bezpieczne po błędzie token endpointu, API oraz cleanup.

### Kroki testowania ręcznego

1. Skonfigurować nieśledzony lokalny `.env` wartościami osobnych projektów i dedykowanych kont testowych.
2. Uruchomić lokalny bootstrap dla brakujących poświadczeń, a następnie niedestrukcyjny readiness kolejno dla Spotify i YouTube; potwierdzić wymagane `config/auth/read` Spotify, wymagane `config/read` YouTube oraz `SKIP` dla niewykonywanych zdolności.
3. Uruchomić `--write` per provider, potwierdzić pełne przywrócenie prywatnego fixture Spotify oraz usunięcie jednorazowej playlisty `unlisted` YouTube; osobno wykonać i zapisać jednorazowy test utworzenia prywatnej playlisty Spotify przez API.
4. Przejrzeć output i logi pod kątem tokenów, PII, fixture IDs, pełnych URL-i i surowych odpowiedzi.
5. Wykonać osobny test unieważnienia oraz ponownej autoryzacji obu kont testowych.
6. Zapisać redagowaną macierz do `verification.md` i jawnie zaznaczyć brak produkcyjnego live smoke.

## Uwagi dotyczące wydajności

Komenda readiness jest narzędziem operatorskim uruchamianym lokalnie i nie należy do ścieżki żądania użytkownika. Każdy request ma krótki connect timeout i ograniczony całkowity timeout; komenda nie wykonuje automatycznych pętli retry. `429`, `Retry-After` i YouTube `quotaExceeded` są klasyfikowane, a nie maskowane długim oczekiwaniem.

Domyślna kwota YouTube 10 000 jednostek dziennie pozwala jedynie na małą liczbę operacji zapisujących po 20 elementów. F-01 utrwala globalny budżet pięciu rozpoczętych zapisów dziennie, lecz mechanizm rezerwacji i egzekwowania powstanie wraz z rzeczywistym przepływem eksportu/synchronizacji.

## Uwagi dotyczące migracji

Zmiana nie modyfikuje bazy danych. Tokeny testowych kont technicznych pozostają w lokalnym, nieśledzonym środowisku, a produkcyjne wartości są zewnętrzną konfiguracją runtime. Zaszyfrowane tokeny kont użytkowników i ich rotacyjny zapis wymagają osobnej migracji w S-04.

## Referencje

- Zakres F-01 i zależności: `context/foundation/roadmap.md:42`, `context/foundation/roadmap.md:79`.
- Kontrakt produktu i bezpieczeństwa: `context/foundation/prd.md:77`, `context/foundation/prd.md:84`, `context/foundation/prd.md:90`, `context/foundation/prd.md:113`.
- Wzorzec bezpiecznego callbacku: `app/Http/Controllers/Auth/GoogleAuthController.php:23`.
- Wzorzec konfiguracji usług: `config/services.php:38`, `.env.example:58`.
- Wcześniejsza separacja auth i streaming: `context/archive/2026-09-12-private-account-and-bank/plan.md:44`.
- Spotify playlist items: `https://developer.spotify.com/documentation/web-api/reference/get-playlists-items`.
- Spotify scopes i authorization: `https://developer.spotify.com/documentation/web-api/concepts/scopes`, `https://developer.spotify.com/documentation/web-api/concepts/authorization`.
- Spotify refresh-token expiration: `https://developer.spotify.com/blog/2026-06-18-refresh-token-expiration`.
- YouTube authentication i server-side OAuth: `https://developers.google.com/youtube/v3/guides/authentication`, `https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps`.
- YouTube playlist operations i quota: `https://developers.google.com/youtube/v3/docs/playlists`, `https://developers.google.com/youtube/v3/determine_quota_cost`.

## Progress

> Konwencja: `- [ ]` oczekujące, `- [x]` wykonane. Dodaj ` — <commit sha>` po wylądowaniu kroku. Nie zmieniaj nazw tytułów kroków. Zobacz `.agents/skills/10x-plan/references/progress-format.md`.

### Phase 1: Kontrakt platform i bezpieczna konfiguracja

#### Automated

- [ ] 1.1 Zweryfikować kompletny i bezsekretny kontrakt konfiguracji Laravel
- [ ] 1.2 Potwierdzić walidację braków i sentineli bez live API
- [ ] 1.9 Zweryfikować cykl autoryzacji YouTube zależny od statusu projektu
- [ ] 1.3 Potwierdzić typowany wynik readiness i JSON schema v1
- [ ] 1.4 Zweryfikować rozszerzony kontrakt źródła
- [ ] 1.5 Sprawdzić formatowanie PHP

#### Manual

- [ ] 1.6 Potwierdzić separację klientów Google login, Spotify i YouTube
- [ ] 1.7 Potwierdzić minimalne scope obu platform
- [ ] 1.8 Potwierdzić granicę lokalnych testów i produkcyjnego PaaS
- [ ] 1.10 Potwierdzić wymagania kont testowych Spotify i YouTube

### Phase 2: Powtarzalna komenda readiness

#### Automated

- [ ] 2.1 Zweryfikować macierz, formaty i exit codes komendy
- [ ] 2.2 Zweryfikować probe Spotify i sklasyfikowane odmowy
- [ ] 2.3 Zweryfikować probe YouTube, paginację, kwotę i cleanup
- [ ] 2.10 Zweryfikować lokalny bootstrap OAuth, state/PKCE i bezpieczny magazyn
- [ ] 2.4 Potwierdzić brak sekretów i identyfikatorów w output oraz logach
- [ ] 2.5 Uruchomić pełny PHPUnit bez sieci i poświadczeń providerów
- [ ] 2.6 Sprawdzić formatowanie PHP

#### Manual

- [ ] 2.7 Potwierdzić diagnostyczną granicę probe'ów
- [ ] 2.8 Potwierdzić brak mutacji bez flagi write
- [ ] 2.9 Potwierdzić jednorazowość zasobów i blokujący cleanup
- [ ] 2.11 Potwierdzić lokalną granicę bootstrapu i brak ręcznego kopiowania tokenów

### Phase 3: Lokalna weryfikacja live i trwały dowód

#### Automated

- [ ] 3.1 Uruchomić pełne bramki repozytorium
- [ ] 3.2 Potwierdzić spójny limit 20 utworów i pięciu zapisów YouTube
- [ ] 3.3 Potwierdzić czysty diff bez sekretów i artefaktów runtime

#### Manual

- [ ] 3.4 Potwierdzić pełną macierz Spotify na koncie testowym
- [ ] 3.5 Potwierdzić pełną macierz YouTube na koncie testowym
- [ ] 3.6 Uzyskać kod zero i bezpieczny JSON dla lokalnych prób read i write
- [ ] 3.7 Potwierdzić unieważnienie i ponowną autoryzację obu platform
- [ ] 3.8 Zatwierdzić redagowany verification bez produkcyjnego smoke
- [ ] 3.9 Potwierdzić zgodność produkcyjnego kontraktu z granicą PaaS
