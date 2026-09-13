# Powiązanie kont streamingowych — plan implementacji

## Przegląd

Zmiana dostarcza wycinek S-04: zalogowany i zweryfikowany użytkownik może
powiązać, ponownie autoryzować i odłączyć po jednym koncie Spotify oraz kanale
YouTube. Połączenia streamingowe pozostają oddzielone od metod logowania,
używają wyłącznie jawnie wymaganych scope'ów, przechowują trwale tylko
szyfrowany refresh token i tracą lokalną możliwość dostępu natychmiast po
odłączeniu albo jednoznacznym odrzuceniu poświadczeń przez providera.

Implementacja należy do aplikacji. Manager jest zewnętrznym PaaS: przechowuje i
dostarcza runtime wyłącznie konfigurację aplikacji providera, w tym client ID,
client secret i publiczne callback URI. Laravel prowadzi OAuth użytkownika,
przechowuje zaszyfrowany refresh token, odświeża grant i wykonuje operacje
produktowe. Manager nie obsługuje callbacków użytkownika, grantów, kont,
playlist, revoke ani innej logiki biznesowej.

## Analiza stanu obecnego

F-01 i S-01 są ukończone. Aplikacja ma sesyjny auth e-mail/Google, chroniony
`/bank`, modele `User` i `AuthIdentity` oraz acceptance probe dla technicznych
i testowych kont Spotify/YouTube. Nie ma ekranu ustawień, modelu kont
streamingowych, webowego OAuth użytkowników ani modeli playlist i
synchronizacji.

`AuthIdentity` zapisuje tylko provider i stabilny subject logowania. Jego
kontrakt oraz testy jawnie wykluczają tokeny, a wcześniejszy plan S-01 oddziela
metody logowania od integracji streamingowych. Probe F-01 dostarczają
sprawdzone wzorce timeoutów, refresh exchange, identyfikacji konta i
bezpiecznych test doubles, ale ich wejścia, rezultaty i rotation sink należą
wyłącznie do protokołu `music-map.platform-access.v1` dla principals
`technical|tester`.

Oficjalna dokumentacja providerów koryguje dwa istotne założenia. Spotify
rekomenduje Authorization Code dla poufnej aplikacji serwerowej, obecnie
ogranicza refresh token aplikacji Dashboard do sześciu miesięcy i publikuje
stabilne `account_id` jako identyfikator do account linking. Google/YouTube
wybiera kanał podczas autoryzacji konta. Aplikacja odczytuje dokładnie jeden
kanał związany z otrzymanym grantem; zero albo niejednoznaczna odpowiedź kończy
przepływ bez zapisu. Revoke tokenu może wycofać granty całego projektu dla
użytkownika.

Spotify Development Mode wymaga aktywnego Premium właściciela aplikacji i
dopuszcza najwyżej pięciu allowlistowanych użytkowników na Client ID. MVP
świadomie pozostaje w tym limicie i współdzieli `SPOTIFY_CLIENT_ID` z F-01;
każde odrębne konto technical, tester i product zajmuje miejsce na tej samej
allowliście. Szerszy rollout albo oddzielny klient produktowy jest poza S-04.

### Kluczowe odkrycia

- `/bank` jest chroniony przez `auth` i `verified`, a istniejący OAuth
  logowania jest guest-only; trasy integracji muszą mieć odrębny middleware,
  callback i przestrzeń stanu (`routes/web.php:9`, `routes/web.php:19`).
- `auth_identities` ma unikalności `(provider, provider_user_id)` i
  `(user_id, provider)` bez tokenów; `streaming_accounts` potrzebuje
  analogicznej własności w osobnej tabeli
  (`database/migrations/2026_09_12_000001_create_auth_identities_table.php:11`).
- Google login używa globalnego klucza sesji `state`, dlatego próby
  integracyjne muszą być namespaced, wielokrotne i jednorazowe
  (`app/Http/Controllers/Auth/GoogleAuthController.php:68`).
- `User` udostępnia obecnie tylko `authIdentities()`; nowa relacja nie może
  zmienić semantyki logowania (`app/Models/User.php:21`).
- Exact scope probe F-01 pozostaje niezmienny. Integracja użytkownika Spotify
  dodatkowo prosi o wybrane `playlist-read-collaborative`; integracja YouTube
  prosi o `https://www.googleapis.com/auth/youtube`
  (`app/Integrations/PlatformAccess/PlatformAccessProtocol.php:23`).
- Probe zapisuje replacement refresh token przed dalszym użyciem access tokenu.
  Dla kont użytkowników ten sam niezmiennik musi być realizowany atomowo w
  bazie, nigdy przez PaaS rotation sink
  (`app/Integrations/PlatformAccess/SpotifyProbe.php:61`,
  `app/Integrations/PlatformAccess/RefreshTokenRotationSink.php:7`).
- Nawigacja ma dziś wyłącznie dane użytkownika i logout; ekran Integracje
  wymaga nowej nazwanej trasy i wejścia w nagłówku
  (`resources/views/components/app-navigation.blade.php:5`).
- Każdy nowy plik PHP pod `app/` lub `tests/` musi trafić do ręcznego
  `required_paths` (`scripts/verify-source-contract:14`,
  `scripts/verify-source-contract:155`).
- Produkcyjna zmiana schematu jest addytywna i przechodzi przez zewnętrzną,
  nadzorowaną bramkę schema-release; zwykły deploy nie wykonuje migracji
  (`context/foundation/infrastructure.md`).

## Pożądany stan końcowy

- Ekran `/integrations` pokazuje osobne karty Spotify i YouTube ze stanem:
  niepołączone, połączone z minimalną etykietą albo wymagające ponownego
  połączenia.
- Każdy użytkownik ma najwyżej jedno konto danego providera, a jedno stabilne
  konto providera należy najwyżej do jednego użytkownika `music-map`.
- Spotify zapisuje stabilne `account_id`, a YouTube wiąże kanał jawnie wybrany
  przez użytkownika podczas consent/login providera.
- OAuth używa osobnych callbacków, provider/purpose/user-bound state z TTL i
  jednorazową konsumpcją. Nie koliduje z logowaniem Google ani między kartami.
- Baza zawiera tylko szyfrowany refresh token. Access token istnieje wyłącznie
  w pamięci requestu i nigdy nie trafia do modelu, sesji, logu ani odpowiedzi.
- Ponowne powiązanie tego samego konta atomowo aktualizuje grant; próba
  zastąpienia go innym kontem bez wcześniejszego unlink kończy się bez zmian.
- `invalid_grant` oznacza „wymaga ponownego połączenia”; timeout lub 5xx nie
  niszczy działającego poświadczenia.
- Unlink usuwa lokalną możliwość dostępu i wywołuje rozszerzalny kontrakt
  wyłączenia zależnych synchronizacji w jednej transakcji. YouTube dostaje
  późniejszą próbę revoke, a Spotify instrukcję `Remove Access`.
- Automatyczna macierz przechodzi bez sieci na SQLite i PostgreSQL, a
  dedykowane konta testowe przechodzą live link/relink/unlink obu providerów.

## Czego NIE robimy

- Nie dodajemy modeli playlist, źródeł, eksportów, harmonogramów ani rekordów
  synchronizacji; S-08 wypełni przygotowany kontrakt wyłączenia synchronizacji.
- Nie implementujemy importu, eksportu ani limitu zapisów YouTube.
- Nie dodajemy więcej niż jednego konta Spotify i jednego kanału YouTube na
  użytkownika.
- Nie zapisujemy access tokenów, kodów autoryzacyjnych, provider payloadów,
  e-maili, avatarów ani pełnych profili.
- Nie używamy `auth_identities`, `PlatformProbe`,
  `RefreshTokenRotationSink` ani technical/tester credentials do OAuth
  użytkownika.
- Nie zmieniamy kontraktu `music-map.platform-access.v1` ani exact scope jego
  probe.
- Nie dodajemy community providera Spotify ani nowej biblioteki OAuth.
- Nie przechodzimy na Spotify Extended Quota Mode ani nie wydzielamy osobnego
  klienta produktowego; S-04 utrzymuje jawny limit Development Mode.
- Nie dodajemy PKCE/DPoP do poufnego web-server flow; oba providerzy
  dokumentują Authorization Code dla tej topologii, a losowy jednorazowy
  `state` chroni callback przed CSRF.
- Nie przenosimy do repozytorium host paths, transportu sekretów, kontenerowych
  argumentów, locków, rollbacku ani innych szczegółów Managera.
- Nie uznajemy zewnętrznego revoke za warunek lokalnego odłączenia i nie
  przywracamy usuniętego tokenu po jego awarii.

## Podejście do implementacji

Powstanie osobny subsystem `App\\Integrations\\StreamingAccounts` z zamkniętym
`StreamingProvider`, typowanymi DTO i własnymi gatewayami opartymi na Laravel
HTTP Client. Gatewaye wykonują tylko authorization URL, code/refresh exchange,
odczyt stabilnej tożsamości i opcjonalny revoke. Stosują connect timeout 5 s,
całkowity timeout 10 s i brak automatycznego retry, zgodnie ze sprawdzonym
wzorcem F-01. Wyniki providerów są mapowane na mały domenowy zestaw błędów bez
tekstu, URL-i, tokenów i payloadów zewnętrznych.

Spotify używa serwerowego Authorization Code z Basic client authentication,
stabilnego `/me.account_id` i scope'ów `playlist-modify-private`,
`playlist-read-private`, `playlist-read-collaborative` oraz
`user-read-private`. YouTube używa osobnego kontekstu Google Authorization
Code z `access_type=offline`, `prompt=consent`, dokładnym callbackiem i scopem
`youtube`. Oba przepływy żądają dokładnie zatwierdzonego zestawu, sprawdzają
obecność wszystkich wymaganych scope'ów w zwróconym grancie i zapisują
znormalizowany faktyczny zestaw; dodatkowe scope'y istniejącego grantu Google
nie rozszerzają funkcji `music-map`.

`StreamingOAuthAttemptStore` utrzymuje w szyfrowanej sesji maksymalnie pięć
prób przez 10 minut. Przechowuje wyłącznie hash state, provider, purpose
`link|reconnect`, user ID i czas utworzenia. Callback konsumuje tylko pasującą
próbę przed code exchange. YouTube wybiera kanał po stronie providera podczas
consent/login; callback wymaga dokładnie jednego kanału dla otrzymanego grantu
i nie przechowuje access tokenu ani pending grantu w sesji.

Krótkie akcje domenowe wykonują transakcje dopiero po zakończeniu requestów
sieciowych. `LinkStreamingAccount` blokuje obecny rekord użytkownika, obsługuje
idempotentny update tego samego konta i zamknięty konflikt własności.
`DisconnectStreamingAccount` blokuje należący do użytkownika rekord, wywołuje
`DisableDependentStreamingSynchronizations` i usuwa rekord. Dopiero po commit
próbuje zewnętrznego revoke na kopii tokenu utrzymywanej w pamięci.

## Krytyczne szczegóły implementacji

### Sekwencjonowanie stanu

Sieć nie może działać wewnątrz transakcji. Każdy wynik refreshu jest
warunkowo zatwierdzany względem `credential_version` odczytanego przed I/O.
Replacement refresh token trzeba utrwalić przez CAS przed użyciem zwróconego
access tokenu przez późniejsze operacje produktu; brak replacement tokenu
zachowuje poprzednią wartość dopiero po potwierdzeniu, że konto nadal istnieje,
należy do tego samego użytkownika, jest connected i ma niezmienioną wersję.
Tylko strukturalne `invalid_grant` zeruje token i ustawia stan reconnect przez
CAS na tej samej wersji; spóźnione `invalid_grant` po relinku niczego nie zmienia.
Awaria transportu pozostawia połączenie bez zmian.

Callback YouTube zapisuje `streaming_accounts` dopiero po potwierdzeniu, że
`channels.list(mine=true)` zwrócił dokładnie jeden kanał dla grantu wybranego
podczas consent/login. Zero albo niejednoznaczna odpowiedź kończy przepływ bez
utworzenia połączenia.

### Cykl życia i bezpieczeństwo

Encrypted cast wymaga kolumny `TEXT`. `APP_KEY` szyfruje rekordy, więc rotacja
klucza musi zachować poprzednie klucze w publicznie wspieranym
`APP_PREVIOUS_KEYS` do czasu ponownego zaszyfrowania lub reautoryzacji
rekordów. Refresh token i authorization code nie mogą być
fillable, serializowane ani umieszczane w kontekście logów.

Google revoke może objąć wszystkie granty użytkownika w tym samym projekcie.
Odłączenie YouTube nie usuwa `AuthIdentity`, nie wylogowuje z `music-map` i
nie zmienia aktywnej sesji; może natomiast spowodować ponowny ekran zgody przy
przyszłym logowaniu Google. Spotify Web API nie ma publikowanego standardowego
revoke, więc unlink nie wykonuje fałszywego wywołania `/unlink-user` z innego
produktu Spotify Open Access.

## Phase 1: Domena i bezpieczne przechowywanie

### Przegląd

Faza tworzy addytywny, niezależny od providerów model własności i cyklu życia
połączenia. Kończy się testowalnym szyfrowanym storage bez kodu OAuth.

### Wymagane zmiany

#### 1. Zamknięty provider i model połączenia

**Pliki**: `app/Enums/StreamingProvider.php`,
`app/Models/StreamingAccount.php`, `app/Models/User.php`

**Cel**: Oddzielić konta streamingowe od metod logowania i wyrazić jeden
spójny lifecycle dla obu providerów.

**Kontrakt**: `StreamingProvider` dopuszcza wyłącznie `spotify` i `youtube`.
`User::streamingAccounts()` jest relacją `HasMany`.
`StreamingAccount` ukrywa refresh token i udostępnia stan `connected`, gdy
token istnieje, oraz `reconnect-required`, gdy token jest `null`. Token nie
jest fillable ani serializowany.

#### 2. Addytywny schemat

**Plik**:
`database/migrations/2026_09_13_000000_create_streaming_accounts_table.php`

**Cel**: Zapisać minimalną tożsamość, grant i szyfrowane poświadczenie bez
mieszania ich z `auth_identities`.

**Kontrakt**: Tabela zawiera `id`, `user_id` z cascade delete, `provider`,
`provider_account_id` do 255 znaków, nullable `label` do 255 znaków,
`scopes` jako JSON, nullable `refresh_token` jako `TEXT`, nullable
`reauthorization_due_at`, `credential_version` jako unsigned bigint z wartością
początkową `1` i timestamps. Unikalne są
`(user_id, provider)` oraz `(provider, provider_account_id)`. Migracja jest
addytywna; `down` usuwa wyłącznie nową tabelę.

#### 3. Szyfrowanie i fabryka

**Pliki**: `app/Models/StreamingAccount.php`,
`database/factories/StreamingAccountFactory.php`

**Cel**: Zapewnić bezpieczne tworzenie rekordów w aplikacji i testach.

**Kontrakt**: `refresh_token` używa Eloquent cast `encrypted`, `scopes`
używa tablicy, daty używają immutable datetime. Fabryka ma jawne stany dla
Spotify, YouTube i reconnect-required oraz korzysta wyłącznie z canary tokenów.

#### 4. Testy migracji i kontraktu danych

**Pliki**:
`tests/Feature/StreamingAccounts/StreamingAccountMigrationTest.php`,
`tests/Feature/StreamingAccounts/StreamingAccountModelTest.php`

**Cel**: Bezpiecznie zweryfikować migrację oraz utrwalić własność, unikalności,
szyfrowanie i serializację przed dodaniem przepływów sieciowych.

**Kontrakt**: Dedykowany test migracji działa przez PHPUnit na testowym
in-memory SQLite i w jednym procesie sprawdza zastosowanie migracji, oba
ograniczenia unikalności oraz jej `down`; nie uruchamia `migrate:fresh` na
połączeniu z bieżącego `.env`. Test modelu obejmuje cascade delete, relację,
normalizację scope'ów, ciphertext w surowej bazie, plaintext przez model, brak
tokenu w `toArray()/toJson()` oraz oba stany połączenia.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Migracja stosuje się, egzekwuje constraints i cofa na testowym in-memory
  SQLite bez użycia połączenia z bieżącego `.env`:
  `php artisan test tests/Feature/StreamingAccounts/StreamingAccountMigrationTest.php`.
- Model, własność, constraints i szyfrowanie przechodzą:
  `php artisan test tests/Feature/StreamingAccounts/StreamingAccountModelTest.php`.
- Brak regresji rozdzielenia metod logowania:
  `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php`.
- Formatowanie PHP przechodzi: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Przegląd schematu potwierdza expand-only, brak access tokenu oraz brak
  plaintextowego sekretu w atrybutach i serializacji.
- Przegląd modelu klucza potwierdza, że planowana rotacja `APP_KEY` zachowa
  możliwość odszyfrowania istniejących integracji.

**Uwaga implementacyjna**: Po zielonych testach zatrzymaj się na przegląd
schematu i cyklu klucza przed budową OAuth.

---

## Phase 2: OAuth i atomowe linkowanie

### Przegląd

Faza dostarcza rozłączne, testowalne web-server OAuth dla obu providerów,
bezpieczne próby sesyjne, providerowy wybór kanału YouTube i atomowe utworzenie lub
ponowną autoryzację połączenia.

### Wymagane zmiany

#### 1. Konfiguracja OAuth użytkownika

**Pliki**: `.env.example`, `config/services.php`

**Cel**: Dodać publiczne nazwy konfiguracji bez mieszania user OAuth z
technical/tester credentials albo callbackiem Google login.

**Kontrakt**: `services.streaming_accounts.spotify` czyta
`SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET` i
`SPOTIFY_REDIRECT_URI`. `services.streaming_accounts.youtube` współdzieli
`GOOGLE_CLIENT_ID` i `GOOGLE_CLIENT_SECRET`, ale czyta osobny
`YOUTUBE_REDIRECT_URI`. Placeholdery nie zawierają wartości sekretów, a oba
callbacki produkcyjne używają exact HTTPS URI. Wspólny klient Spotify jest
świadomą granicą MVP: przed live smoke operator potwierdza aktywne Premium
właściciela oraz że wszystkie odrębne konta technical, tester i product
mieszczą się w pięciu miejscach allowlisty.

#### 2. Gatewaye OAuth i typowane DTO

**Pliki**:
`app/Integrations/StreamingAccounts/Contracts/StreamingOAuthGateway.php`,
`app/Integrations/StreamingAccounts/Data/StreamingGrant.php`,
`app/Integrations/StreamingAccounts/Data/StreamingIdentity.php`,
`app/Integrations/StreamingAccounts/StreamingOAuthFailure.php`

**Cel**: Oddzielić domenę i kontrolery od protokołów Spotify oraz Google.

**Kontrakt**: Gateway udostępnia authorization URL, code exchange, refresh,
odczyt stabilnej tożsamości i opcjonalny revoke. `StreamingGrant` może zawierać
access token i refresh token tylko jako krótkotrwałe dane procesu; nie jest
serializowany do sesji, kolejki, logu ani odpowiedzi. Błędy są zamkniętą
taksonomią bez odbijania tekstu i payloadu providera. Spotify 403 podczas
tożsamości lub wymiany dla konta spoza allowlisty mapuje się na neutralny,
bezsekretny wynik niedostępności dostępu, bez sugerowania awarii hasła albo
ujawniania konfiguracji Dashboardu.

#### 3. Adaptery Spotify i YouTube

**Pliki**:
`app/Integrations/StreamingAccounts/SpotifyOAuthGateway.php`,
`app/Integrations/StreamingAccounts/YouTubeOAuthGateway.php`,
`app/Providers/StreamingAccountsServiceProvider.php`, `bootstrap/providers.php`

**Cel**: Zrealizować web-server OAuth w aplikacji przez Laravel HTTP Client.

**Kontrakt**: Gatewaye używają stałych bazowych endpointów, connect timeout 5 s,
timeout 10 s i braku automatycznego retry. Spotify używa Basic client auth;
YouTube używa `access_type=offline` i `prompt=consent`. Adaptery nie logują
authorization URL, callback code, tokenów ani payloadów i nie zmieniają
istniejącego `PlatformAccess`.

#### 4. Scope i tożsamość providerów

**Plik**: `app/Enums/StreamingProvider.php`

**Cel**: Utrwalić minimalny kontrakt wymagany przez S-02, S-07 i S-08.

**Kontrakt**: Spotify wymaga `playlist-modify-private`,
`playlist-read-private`, `playlist-read-collaborative` i `user-read-private`;
YouTube wymaga dokładnie scope'u `youtube`. Gateway Spotify odczytuje stabilne
`account_id`; gateway YouTube wymaga dokładnie jednego kanału zwróconego przez
`channels.list(mine=true)` dla grantu wybranego podczas consent/login.

#### 5. Rejestr zależności

**Pliki**:
`app/Providers/StreamingAccountsServiceProvider.php`,
`bootstrap/providers.php`

**Cel**: Powiązać providerów z gatewayami i umożliwić testowanie bez sieci.

**Kontrakt**: Provider rejestruje gatewaye i akcje S-04, nie zmienia
`FortifyServiceProvider` ani bindingów `PlatformAccess`. Nieznany provider
kończy się przed requestem sieciowym. Provider wiąże aplikacyjny port
`Contracts\\WithStreamingAccess` z `Actions\\WithStreamingAccess`, aby
konsumenci i testy mogli podmieniać implementację bez zależności od gatewaya.
W `boot()` rejestruje nazwany limiter `streaming-oauth`: 10 prób na minutę dla
klucza `<user-id>|<provider>`. Connect i callback biorą provider wyłącznie z
ograniczonego parametru trasy. Verify ustala go przez właścicielską relację
`User::streamingAccounts()`; brakujący albo cudzy identyfikator trafia do jednego
klucza `unknown`, bez globalnego model bindingu i bez requestu do providera.

#### 6. Próby OAuth

**Pliki**:
`app/Integrations/StreamingAccounts/StreamingOAuthAttemptStore.php`

**Cel**: Związać wiele kart z właściwym użytkownikiem i callbackiem.

**Kontrakt**: Attempt store używa namespace `streaming_oauth.attempts`, hasha
lokalny state, TTL 10 minut, limitu pięciu prób i one-time consume powiązanego
z providerem, purpose i user ID. Nie przechowuje code, grantu ani tokenu.

#### 7. Atomowe akcje link/reconnect i efemerycznego dostępu

**Pliki**:
`app/Integrations/StreamingAccounts/Contracts/WithStreamingAccess.php`,
`app/Integrations/StreamingAccounts/Data/StreamingAccessContext.php`,
`app/Integrations/StreamingAccounts/Data/StreamingAccessResult.php`,
`app/Integrations/StreamingAccounts/StreamingAccessFailure.php`,
`app/Integrations/StreamingAccounts/Actions/LinkStreamingAccount.php`,
`app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php`,
`app/Integrations/StreamingAccounts/Actions/MarkStreamingAccountReconnectRequired.php`

**Cel**: Centralnie egzekwować własność, idempotencję, rotację poświadczeń i
bezpieczne użycie access tokenu przez S-02, S-07 oraz S-08.

**Kontrakt**: `Contracts\\WithStreamingAccess` jest aplikacyjnym portem
publikowanym dla S-02, S-07 i S-08. Przyjmuje właścicielskie
`StreamingAccount`, znormalizowany wymagany zestaw scope'ów oraz synchroniczny,
niekolejkowany callback. Callback przyjmuje tylko `StreamingAccessContext`
z providerem, stabilnym `provider_account_id` i access tokenem; kontekst nie
może być serializowany, kolejkowany, utrwalany ani zwracany z callbacku.
`StreamingAccessResult` przenosi wyłącznie wynik callbacku albo jeden zamknięty
`StreamingAccessFailure`: `reconnect_required`, `missing_scope`,
`stale_credential`, `rate_limited`, `quota_exceeded` lub
`temporarily_unavailable`; nigdy nie zawiera tokenu ani provider payloadu.

Code exchange i odczyt tożsamości kończą sieć przed krótką
transakcją. Link
blokuje `(user_id, provider)`, tworzy brakujący rekord lub aktualizuje wyłącznie
ten sam `provider_account_id`; inne konto wymaga unlink. Globalny konflikt jest
neutralny. Ponieważ blokada nie obejmuje nieistniejącego wiersza, naruszenie
unikalności przy pierwszym równoległym INSERT powoduje ponowienie całej krótkiej
transakcji i ponowny odczyt obu kluczy własności, zgodnie z istniejącym wzorcem
`ResolveGoogleIdentity`. Ten sam `provider_account_id` daje idempotentny sukces,
inne konto tego użytkownika wymaga unlink, a konto należące do innego
użytkownika daje neutralną odmowę; surowy wyjątek constraintu nie wychodzi do
kontrolera. Zmiana refresh tokenu albo statusu zwiększa `credential_version`.
`Actions\\WithStreamingAccess` implementuje port. Odszyfrowuje refresh token
wyłącznie wewnątrz akcji, zapamiętuje bieżący `credential_version` i odświeża
poza transakcją. Przed callbackiem wykonuje krótki warunkowy guard dla każdego
udanego refreshu: replacement token zapisuje przez CAS ze zwiększeniem wersji,
a przy braku replacement tokenu potwierdza istnienie, własność, stan connected
i niezmienioną wersję rekordu. Callback otrzymuje kontekst tylko po udanym
guardzie. Spóźniony wynik dla usuniętego rekordu lub innej wersji nie uruchamia
operacji produktu. `MarkStreamingAccountReconnectRequired` zeruje token po
`invalid_grant` wyłącznie przez CAS na wersji odczytanej przed requestem;
nieudany CAS niczego nie zmienia. Timeout/5xx nie zmienia działającego
poświadczenia.

#### 8. Trasy i callback

**Pliki**:
`app/Http/Controllers/StreamingAccountOAuthController.php`,
`routes/web.php`

**Cel**: Udostępnić bezpieczne wejście, callback i ręczne sprawdzenie grantu.

**Kontrakt**: Nazwane trasy POST connect, GET callback i właścicielski
`POST /integrations/{streamingAccount}/verify` /
`integrations.accounts.verify` działają pod `auth`, `verified` oraz limiterem
`throttle:streaming-oauth` per user/provider. Connect tworzy attempt, callback najpierw konsumuje state i
obsługuje cancel, a dopiero potem wykonuje code exchange i odczyt tożsamości
przez właściwy gateway. Verify rozwiązuje rekord wyłącznie przez
`User::streamingAccounts()`, uruchamia `WithStreamingAccess` z callbackiem bez
operacji produktowej i pokazuje connected, reconnect-required albo neutralny
błąd tymczasowy. Każdy wynik wraca do `integrations.index` bez tokenów i
provider payloadu w sesji flash.

#### 9. Testy kontraktu i poufności

**Pliki**:
`tests/Unit/Integrations/StreamingAccounts/SpotifyOAuthGatewayTest.php`,
`tests/Unit/Integrations/StreamingAccounts/YouTubeOAuthGatewayTest.php`,
`tests/Unit/Integrations/StreamingAccounts/WithStreamingAccessTest.php`,
`tests/Feature/StreamingAccounts/StreamingOAuthTest.php`

**Cel**: Pokryć gatewaye i najdroższe przypadki brzegowe bez prawdziwej sieci.

**Kontrakt**: `Http::fake` i fake gatewaye pokrywają replay/expiry state,
cancel, scope/account mismatch, reconnect, Spotify 403 dla konta spoza
allowlisty, rate/quota, timeout/5xx, spóźniony
wynik, rotację i CAS oraz brak code, access tokenów i provider payloadów w
bazie, HTML, sesji, kolejkach oraz logach. Test tego samego `updated_at`
potwierdza, że CAS używa `credential_version`. Osobne przypadki dowodzą, że
refresh bez replacement tokenu po równoległym unlink/relink nie uruchamia
callbacku oraz że spóźnione `invalid_grant` nie zeruje tokenu zapisanego przez
relink. Test kontraktowy utrwala sygnaturę portu, zamknięte failure codes,
stabilną tożsamość w kontekście i możliwość podmiany fake'em bez sieci.
Test linkowania wymusza kolizję pierwszego INSERT-u i potwierdza ponowną
klasyfikację na idempotentny sukces, wymagany unlink albo neutralny konflikt.
Test limitera potwierdza osobne budżety user/provider, wspólny klucz `unknown`
dla brakujących i cudzych identyfikatorów oraz odpowiedź 429 wykonującą zero
requestów do providera.
Surowa baza zawiera wyłącznie ciphertext refresh tokenu.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Gatewaye przechodzą macierz exchange, refresh, identity, revoke i błędów:
  `php artisan test tests/Unit/Integrations/StreamingAccounts/SpotifyOAuthGatewayTest.php tests/Unit/Integrations/StreamingAccounts/YouTubeOAuthGatewayTest.php`.
- State, wybrana tożsamość kanału, konflikty i callbacki przechodzą:
  `php artisan test tests/Unit/Integrations/StreamingAccounts/WithStreamingAccessTest.php tests/Feature/StreamingAccounts/StreamingOAuthTest.php`.
- Testy dowodzą braku access tokenu w storage i bezpiecznego szyfrowania refresh
  tokenu.
- Regresja Google login i probe F-01 przechodzi:
  `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php tests/Unit/Integrations/PlatformAccess tests/Feature/Console/ProbePlatformAccessTest.php`.
- Formatowanie i frontend przechodzą:
  `vendor/bin/pint --test && npm run build`.

#### Weryfikacja ręczna

- Przegląd żądań potwierdza exact scope'y, osobny state i stałe endpointy
  providerów.
- Przegląd bazy, błędów i logów potwierdza brak code, access tokenów,
  plaintextowego refresh tokenu i PII.

**Uwaga implementacyjna**: Manager dostarcza wyłącznie aplikacyjne ustawienia
runtime. Po testach zatrzymaj się na literalny przegląd granicy przed UI/unlink.

---

## Phase 3: Odłączenie i ekran Integracje

### Przegląd

Faza dostarcza docelowe zarządzanie kontami, lokalnie bezwarunkowe odłączenie,
best-effort revoke bezpośrednio u providera oraz jawny punkt rozszerzenia dla
S-08.

### Wymagane zmiany

#### 1. Kontrakt wyłączenia synchronizacji

**Pliki**:
`app/Integrations/StreamingAccounts/Contracts/DisableDependentStreamingSynchronizations.php`,
`app/Integrations/StreamingAccounts/Actions/NoopDisableDependentStreamingSynchronizations.php`,
`app/Providers/StreamingAccountsServiceProvider.php`

**Cel**: Zapewnić jedno miejsce egzekwowania FR-006 bez projektowania
nieistniejącego schematu S-08.

**Kontrakt**: Synchroniczny kontrakt przyjmuje zablokowane konto streamingowe i
działa w transakcji odłączenia. Implementacja S-04 jest jawnym no-op, ponieważ
nie istnieją zależne rekordy; S-08 zastąpi binding operacją bulk-disable bez
zmiany kontrolera ani kolejności unlink.

#### 2. Akcja odłączenia

**Pliki**:
`app/Integrations/StreamingAccounts/Actions/DisconnectStreamingAccount.php`,
`app/Http/Controllers/StreamingAccountController.php`,
`routes/web.php`

**Cel**: Natychmiast zakończyć lokalny dostęp niezależnie od dostępności
providera.

**Kontrakt**: `StreamingAccountController::destroy()` obsługuje nazwaną trasę
`DELETE /integrations/{streamingAccount}` / `integrations.destroy` pod
middleware `auth` i `verified`. Wymaga CSRF, jawnego pola potwierdzenia i
rozwiązuje rekord wyłącznie przez `User::streamingAccounts()`, nigdy globalnie.
Transakcja blokuje rekord, odszyfrowuje refresh token tylko do pamięci procesu,
wywołuje kontrakt synchronizacji i usuwa rekord. Po commit gateway YouTube
wykonuje best-effort revoke; awaria nie odtwarza rekordu. Spotify nie wykonuje
nieistniejącego endpointu revoke i zawsze kończy lokalnym sukcesem z instrukcją
ręcznego `Remove Access`.

#### 3. Ekran Integracje i nawigacja

**Pliki**:
`app/Http/Controllers/StreamingAccountController.php`,
`routes/web.php`,
`resources/views/integrations/index.blade.php`,
`resources/views/components/app-navigation.blade.php`,
`resources/css/app.css`

**Cel**: Dać użytkownikowi jedno stabilne, dostępne miejsce zarządzania obiema
platformami.

**Kontrakt**: `StreamingAccountController::index()` obsługuje nazwaną trasę
`GET /integrations` / `integrations.index` pod middleware `auth` i `verified`,
pobiera przez `User::streamingAccounts()` najwyżej dwa rekordy i pokazuje dwie
karty, minimalną label oraz trzy stany bez account ID, scope'ów i tokenów.
Connect, reconnect i „Sprawdź połączenie” są formularzami POST. Weryfikacja
jest dostępna dla stanu connected i wywołuje właścicielską trasę verify. Unlink
używa dostępnego modala Flux z opisem skutków dla importu, eksportu i przyszłej
synchronizacji; fokus wraca do wyzwalacza, Escape zamyka modal, a backend nie
ufa samemu UI.

#### 4. Testy dostępu, unlink i UI

**Pliki**:
`tests/Feature/StreamingAccounts/StreamingAccountManagementTest.php`,
`tests/Feature/StreamingAccounts/StreamingAccountAuthorizationTest.php`

**Cel**: Udowodnić izolację użytkowników, kolejność odłączenia i stabilny
kontrakt widoku.

**Kontrakt**: Testy obejmują guest/unverified, route constraints, CSRF,
cross-user ID, brak potwierdzenia, connected/reconnect state, no-op seam,
usunięcie przed revoke providera, potwierdzoną revocation, niedostępność providera,
instrukcję manualnego odłączenia, zachowanie `AuthIdentity` i sesji oraz brak
sekretów w odpowiedzi i logach. Asercje tras obejmują nazwy, metody,
middleware i właścicielskie rozwiązywanie bez globalnego model bindingu.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Zarządzanie i wszystkie wyniki unlink przechodzą:
  `php artisan test tests/Feature/StreamingAccounts/StreamingAccountManagementTest.php`.
- Granice auth, verified i cross-user przechodzą:
  `php artisan test tests/Feature/StreamingAccounts/StreamingAccountAuthorizationTest.php`.
- Pełna macierz streaming accounts przechodzi:
  `php artisan test tests/Feature/StreamingAccounts tests/Unit/Integrations/StreamingAccounts`.
- Widoki kompilują się: `npm run build`.
- Formatowanie przechodzi: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Ekran i modal działają klawiaturą, zachowują fokus, są czytelne przy 200%
  zoom i na małym oraz dużym ekranie.
- Teksty skutków i wyników jednoznacznie odróżniają lokalne odłączenie,
  potwierdzoną revocation YouTube oraz wymaganą czynność ręczną Spotify.

**Uwaga implementacyjna**: Po zielonych testach zatrzymaj się na ręczną
weryfikację UX i komunikatów bezpieczeństwa.

---

## Phase 4: Utwardzenie i wydanie

### Przegląd

Faza rozszerza bramki repozytorium, poprawia dokumentację granic, przeprowadza
kontrolowaną zmianę schematu i wymaga realnego smoke obu providerów.

### Wymagane zmiany

#### 1. PostgreSQL i pełne bramki CI

**Pliki**: `.github/workflows/ci.yml`,
`tests/Unit/ProductionInfrastructureTest.php`

**Cel**: Chronić constraints, szyfrowane poświadczenia i krytyczne przepływy na
docelowym silniku bazy.

**Kontrakt**: Istniejący PostgreSQL smoke po `migrate:fresh` uruchamia test
modelu, linkowania, równoległego pierwszego INSERT-u, konfliktu własności, CAS
po `credential_version` i unlink.
Nie dodaje prawdziwych sekretów ani sieci; obrazy i workflow pozostają
przypięte zgodnie z obecnym kontraktem. Jest to aplikacyjna migracja addytywna
obsługiwana przez istniejącą publiczną operację PaaS `schema-release`; nie
wymaga zmiany mechaniki zarządzania PostgreSQL w repozytorium Managera.

#### 2. Kontrakt źródła i dokumentacja

**Pliki**: `scripts/verify-source-contract`, `README.md`

**Cel**: Włączyć każdy nowy plik PHP do manifestu i poprawić historyczne
stwierdzenia o tokenach/OAuth.

**Kontrakt**: README zabrania przechowywania access tokenów i dokumentuje
zaszyfrowany refresh token, aplikacyjny `WithStreamingAccess`, symboliczne
ustawienia klienta oraz stan reconnect. Wyraźnie zapisuje, że Manager
przechowuje i dostarcza aplikacyjne client credentials/runtime config, ale nie
wykonuje OAuth użytkownika ani operacji produktowych; bez host paths,
transportu i lifecycle sekretów Managera.

#### 3. Weryfikacja migracji i realnych providerów

**Plik**:
`context/changes/streaming-account-linking/reviews/manual-verification.md`

**Cel**: Zachować dowód, że konfiguracja, zgody i zachowanie live odpowiadają
przetestowanemu kontraktowi.

**Kontrakt**: Artefakt zapisuje datę, środowisko, commit, nazwy wymaganych
ustawień runtime i obserwacje bez sekretów. Przed live smoke addytywna migracja przechodzi
zewnętrzną, nadzorowaną bramkę schema-release. Smoke wykonuje link, relink,
refresh/verify i unlink na dedykowanych kontach Spotify i YouTube oraz
potwierdza brak access tokenów i plaintextowych refresh tokenów w bazie, HTML
i ograniczonych logach. Artefakt potwierdza też bez identyfikatorów kont aktywne
Premium właściciela klienta Spotify, liczbę zajętych miejsc allowlisty i
zmieszczenie technical/tester/product w limicie pięciu użytkowników.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełny PHPUnit przechodzi: `composer test`.
- PHP formatting przechodzi: `vendor/bin/pint --test`.
- Produkcyjny frontend buduje się: `npm run build`.
- Manifest źródła przechodzi:
  `sh scripts/verify-source-contract --worktree`.
- Audyty zależności przechodzą:
  `composer audit --locked --no-interaction && npm audit --audit-level=high`.
- PostgreSQL CI wykonuje krytyczną macierz S-04 po `migrate:fresh`.

#### Weryfikacja ręczna

- Dedykowane konto Spotify przechodzi link, relink, reconnect i unlink z
  poprawnym ekranem zgody oraz instrukcją `Remove Access`.
- Spotify Development Mode ma aktywne Premium właściciela i wystarczającą
  liczbę miejsc allowlisty dla uzgodnionych kont MVP.
- Dedykowane konto Google/Brand Account przechodzi providerowy wybór kanału,
  link, relink i unlink z próbą revoke bez usunięcia login identity lub sesji.
- Baza, HTML i ograniczone logi nie zawierają access tokenów, code,
  plaintextowych refresh tokenów ani provider payloadów.
- Kontrolowane wydanie schematu i HTTPS smoke są udokumentowane w
  `reviews/manual-verification.md` dla exact commitu.

## Strategia testowania

### Testy jednostkowe

- Gatewaye OAuth: exact endpoint/auth/query, bounds, scope, timeout, brak retry,
  `invalid_grant`, rate/quota, 5xx, zły JSON i brak wymaganych pól.
- Attempt store: losowość, hash state, wiele kart, TTL, limit, jednokrotna
  konsumpcja oraz związanie z user/provider/purpose.
- Zamknięte enumy, DTO i failure mapping bez odbijania treści providera.

### Testy integracyjne

- Model na SQLite i PostgreSQL: constraints, cascade, szyfrowanie tokenu,
  serializacja i connected/reconnect lifecycle.
- Callbacki z fake gatewayami: cancel, state mismatch/replay, idempotentne linkowanie,
  konflikt użytkownika, zmiana konta i zweryfikowana tożsamość providera.
- Weryfikacja zapisanego konta: refresh/CAS, `invalid_grant → reconnect`,
  timeout/5xx bez zmiany, cross-user i odrzucenie spóźnionego wyniku.
- Unlink: transakcyjna kolejność, seam synchronizacji, lokalny sukces przy
  awarii revoke providera, zachowanie Google login identity i izolacja userów.
- Regresja całego auth i `PlatformAccess`.

### Kroki testowania ręcznego

1. Potwierdzić, że Manager dostarcza wymagane symboliczne ustawienia runtime,
   a exact HTTPS return URI aplikacji są zarejestrowane u Spotify i Google.
2. Połączyć Spotify, sprawdzić cztery scope'y, etykietę i brak surowego ID w UI.
3. Ponownie autoryzować to samo Spotify, a próbę innego konta potwierdzić jako
   bezpiecznie odrzuconą do czasu unlink.
4. Podczas consent/login Google/YouTube wybrać kanał Brand Account i sprawdzić
   zapamiętane channel ID przez zachowanie, nie przez ekspozycję UI.
5. Odłączyć oba konta przez modal; potwierdzić zachowanie sesji `music-map`,
   wynik revoke YouTube i instrukcję Spotify.
6. Unieważnić testowy grant providera, użyć „Sprawdź
   połączenie” i potwierdzić zachowanie niesensytywnej etykiety.
7. Sprawdzić klawiaturę, fokus, 200% zoom, mały ekran oraz brak sekretów w
   HTML, bazie i ograniczonych logach.

## Uwagi dotyczące wydajności

Linkowanie i unlink są rzadkimi interakcjami użytkownika. Gatewaye stosują
connect timeout 5 s, całkowity timeout 10 s i nie wykonują automatycznego retry
w aktywnym request. Odczyty ekranu Integracje używają
jednej relacji ograniczonej do dwóch rekordów, więc cache nie jest potrzebny.

## Uwagi dotyczące migracji

Migracja wyłącznie dodaje tabelę metadanych oraz szyfrowanego refresh tokenu i jest
zgodna ze starym obrazem, który jej nie odczytuje. Produkcyjny reconciler
nie uruchamia migracji; exact kandydat
przechodzi publiczną, nadzorowaną operację schema-release przed obrazem
wymagającym tabeli. Rollback aplikacji nie wykonuje `down` i pozostawia
nieużywaną tabelę. Usunięcie tabeli jest osobną destrukcyjną decyzją, nie
elementem rollbacku S-04.

## Referencje

- Zakres produktu: `context/foundation/prd.md` — FR-006 i NFR-003.
- Kolejność: `context/foundation/roadmap.md` — S-04.
- Ukończony auth: `context/archive/2026-09-12-private-account-and-bank/plan.md`.
- Ukończony probe: `context/archive/2026-09-12-platform-access-readiness/plan.md`.
- Spotify Authorization Code:
  `https://developer.spotify.com/documentation/web-api/tutorials/code-flow`.
- Spotify refresh lifecycle:
  `https://developer.spotify.com/documentation/web-api/tutorials/refreshing-tokens`.
- Spotify stable account ID:
  `https://developer.spotify.com/documentation/web-api/reference/get-current-users-profile`.
- Spotify scopes:
  `https://developer.spotify.com/documentation/web-api/concepts/scopes`.
- Spotify quota modes:
  `https://developer.spotify.com/documentation/web-api/concepts/quota-modes`.
- Google web-server OAuth i revoke:
  `https://developers.google.com/identity/protocols/oauth2/web-server`.
- YouTube OAuth/scopes:
  `https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps`.
- YouTube channel identity:
  `https://developers.google.com/youtube/v3/docs/channels/list`.
- Publiczna granica PaaS: `AGENTS.md` i globalny `s-manager-use`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a
> step lands. Do not rename step titles.

### Phase 1: Domena i bezpieczne przechowywanie

#### Automated

- [x] 1.1 Test migracji bezpiecznie sprawdza up, constraints i down na in-memory SQLite
- [x] 1.2 Model, własność, constraints i szyfrowanie przechodzą
- [x] 1.3 Brak regresji rozdzielenia metod logowania
- [x] 1.4 Formatowanie PHP przechodzi

#### Manual

- [ ] 1.5 Przegląd schematu potwierdza expand-only i brak plaintextowych sekretów
- [ ] 1.6 Przegląd modelu potwierdza szyfrowanie refresh tokenu i cykl APP_KEY

### Phase 2: OAuth i atomowe linkowanie

#### Automated

- [ ] 2.1 Gatewaye przechodzą macierz exchange, refresh, identity, revoke i błędów
- [ ] 2.2 State, wybrana tożsamość, konflikty i callbacki przechodzą
- [ ] 2.3 Testy dowodzą braku access tokenu w storage i szyfrowania refresh tokenu
- [ ] 2.4 Regresja Google login i probe F-01 przechodzi
- [ ] 2.5 Formatowanie i frontend przechodzą

#### Manual

- [ ] 2.6 Przegląd żądań potwierdza scope, state i stałe endpointy providerów
- [ ] 2.7 Przegląd bazy i logów potwierdza brak access tokenów, plaintext refresh tokenu i PII

### Phase 3: Odłączenie i ekran Integracje

#### Automated

- [ ] 3.1 Zarządzanie i wszystkie wyniki unlink przechodzą
- [ ] 3.2 Granice auth, verified i cross-user przechodzą
- [ ] 3.3 Pełna macierz streaming accounts przechodzi
- [ ] 3.4 Widoki kompilują się
- [ ] 3.5 Formatowanie przechodzi

#### Manual

- [ ] 3.6 Ekran i modal przechodzą weryfikację dostępności i responsywności
- [ ] 3.7 Teksty rozróżniają unlink, revocation YouTube i czynność ręczną Spotify

### Phase 4: Utwardzenie i wydanie

#### Automated

- [ ] 4.1 Pełny PHPUnit przechodzi
- [ ] 4.2 PHP formatting przechodzi
- [ ] 4.3 Produkcyjny frontend buduje się
- [ ] 4.4 Manifest źródła przechodzi
- [ ] 4.5 Audyty zależności przechodzą
- [ ] 4.6 PostgreSQL CI wykonuje krytyczną macierz S-04

#### Manual

- [ ] 4.7 Dedykowane konto Spotify przechodzi pełny cykl live
- [ ] 4.8 Konto YouTube/Brand Account przechodzi providerowy wybór kanału i pełny cykl live
- [ ] 4.9 Baza, HTML i logi nie zawierają access tokenów ani plaintext refresh tokenów
- [ ] 4.10 Kontrolowane wydanie schematu i HTTPS smoke są udokumentowane
