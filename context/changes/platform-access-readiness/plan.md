# Gotowość dostępu do Spotify i YouTube — plan implementacji

## Przegląd

Zmiana implementuje po stronie `music-map` publiczny kontrakt
`music-map.platform-access.v1`. Manager jest zewnętrznym PaaS: zarządza OAuth,
poświadczeniami technicznymi, ich rotacją i dostarczeniem, uruchamia probe oraz
obsługuje revoke i recovery. Aplikacja udostępnia wyłącznie deterministyczną
komendę `php artisan platform-access:probe`, waliduje wejście, wykonuje
minimalną próbę providera i zwraca zamknięty wynik JSON.

Plan utrwala pełny kontrakt konsumencki w zwykłym checkoutcie repozytorium, aby
implementacja i testy nie zależały od kodu, hosta ani wewnętrznych planów
Managera.

## Analiza stanu obecnego

Repozytorium nie implementuje jeszcze Spotify, YouTube ani
`platform-access:probe`. `config/services.php` zawiera tylko klienta Google
używanego do logowania, a `.env.example` nie ma technicznych ustawień
platform-access. `AuthIdentity` przechowuje stabilne identyfikatory logowania,
nie tokeny platform.

Istniejące bezpieczne wzorce są wąskie, ale użyteczne: sekrety w
`.env.example` mają sentinel `__REQUIRED_RUNTIME_SECRET__`, callback Google
loguje wyłącznie klasę wyjątku i correlation ID, a nginx nie zapisuje pełnych
URI. Nowy subsystem nie może zakładać globalnej redakcji logów; musi jawnie
ograniczyć emitowane pola.

### Kluczowe odkrycia

- Manager wywołuje aplikację; aplikacja nigdy nie wywołuje `s-manager`.
- Publiczny entrypoint to `platform-access:probe`, nie stare
  `platforms:authorize` ani `platforms:readiness`.
- Zamknięty raw-argv preflight musi działać w `artisan` przed
  `Application::handleCommand()`: override pojedynczej komendy następuje za późno,
  aby przejąć nieopcyjny token umieszczony przed nazwą komendy.
- `technical` i `tester` mają rozłączne źródła danych i nie mogą mieć
  fallbacku między tożsamościami.
- YouTube współdzieli `GOOGLE_CLIENT_ID` i `GOOGLE_CLIENT_SECRET` z logowaniem,
  ale grant, refresh token, scope i dane konta platform-access pozostają osobne.
- Exact Spotify scope set to `playlist-modify-private`,
  `playlist-read-private`, `user-read-private`; exact YouTube scope set to
  `https://www.googleapis.com/auth/youtube`.
- `scripts/verify-source-contract` jest ręcznym manifestem, więc każdy nowy
  stabilny plik aplikacji i testów musi zostać dopisany.

## Pożądany stan końcowy

- Gotowy do przekazania PaaS, zreviewowany obraz FPM obsługuje dokładne
  wywołanie v1 i tylko dozwolone wartości provider/principal.
- `technical` czyta wyłącznie jawne ustawienia runtime, zawsze wymienia refresh
  token, potwierdza konto, exact scope i odczyt bez mutowania zasobu providera.
- `tester` czyta wyłącznie zamkniętą sesję z publicznego locatora, wykonuje
  identity/read/write oraz obowiązkowy cleanup na trzech elementach
  zarezerwowanej, początkowo pustej fixture providera.
- Sukces i błąd mają zamknięte schematy, właściwy strumień, końcowy newline oraz
  stabilne exit codes `0`, `1` i `2`.
- Jeżeli provider zwróci replacement refresh token, probe przekazuje go
  wyłącznie przez prywatny sink Managera; Manager przejmuje go przed uznaniem
  sukcesu, bez emisji sekretu do stdout, stderr albo logów.
- Automatyczne testy używają Laravel HTTP fake, nie wymagają sekretów ani sieci
  i dowodzą braku danych providera w outputach oraz logach.
- Zwykły checkout zawiera cały kontrakt potrzebny implementatorowi; nie wymaga
  dostępu do `/srv/manager`.

## Czego NIE robimy

- Nie implementujemy OAuth callback, PKCE, autoryzacji, trwałego
  przechowywania ani recovery poświadczeń technicznych. Aplikacja zapisuje
  ewentualny replacement refresh token tylko do efemerycznego sinka
  dostarczonego i przejmowanego przez Managera.
- Nie implementujemy komend `s-manager`, hostowego transportu, mountów,
  uprawnień, blokad, journali, receiptów ani rollbacku PaaS.
- Nie dodajemy UI, tras webowych, modeli tokenów użytkowników, importu,
  eksportu domenowego, synchronizacji ani licznika kwoty.
- Nie zmieniamy znaczenia istniejącego `services.google` ani
  `GOOGLE_REDIRECT_URI` używanego przez logowanie.
- Nie dodajemy human-readable outputu, nowych kategorii błędów, alternatywnego
  protokołu ani fallbacku między `technical` i `tester`.
- Nie zapisujemy tokenów trwale ani nie emitujemy tokenów, sekretów,
  identyfikatorów kont, fixture, zasobów, PII, pełnych URL-i, surowych
  odpowiedzi lub tekstów wyjątków do protokołu, logów ani trwałych plików.
  Jedynym wyjątkiem jest efemeryczny replacement refresh token zapisany do
  prywatnego sinka Managera opisanego w kontrakcie v1.

## Podejście do implementacji

Komenda będzie cienkim orkiestratorem nad zamkniętą walidacją v1 i dwoma
provider probe'ami. Wspólna warstwa przyjmie wyłącznie znormalizowane DTO,
wyrenderuje dokładnie jeden dokument sukcesu albo błędu i będzie jedynym
miejscem mapowania kategorii oraz kodów procesu. Proste, zamknięte schematy
będą walidowane typowanym kodem aplikacji bez nowej biblioteki JSON Schema.

Probe'y Spotify i YouTube korzystają z Laravel HTTP client z krótkim connect
timeoutem, ograniczonym całkowitym timeoutem i bez automatycznego retry.
Provider-controlled dane nigdy nie trafiają do komunikatów błędów ani logów.
Każdy tester probe używa `try/finally`; `cleanup-failed` ma pierwszeństwo
przed wcześniejszą awarią.

Ścieżki pozostające pod kontrolą `platform-access:probe` obowiązuje reguła
zero logów: nie wywołują `Log::*` ani innego loggera nawet dla bezpiecznych
komunikatów, ponieważ produkcyjny kanał logów współdzieli stderr z odpowiedzią
protokołu. Po skutecznym dispatchu granica komendy przechwytuje każdy
nieobsłużony `Throwable` z walidacji i probe'ów przed standardowym raportowaniem
Kernela i mapuje go bez tekstu wyjątku na `provider-unavailable`. Awaria
autoloadu, bootstrappingu, discovery albo konstrukcji komendy przed dispatchem
nie jest odpowiedzią protokołu aplikacyjnego; Manager traktuje brak lub
niezgodny wynik jako fail-closed błąd wykonania. Plan nie dodaje własnego
Console Kernela. Ręczne uruchomienie lokalne po skutecznym dispatchu używa tego
samego zamkniętego JSON; zmiana nie dodaje osobnego trybu diagnostycznego ani
human-readable.

## Publiczny kontrakt `music-map.platform-access.v1`

Ta sekcja jest normatywnym kontraktem aplikacyjnym. Zmiana jej pól, wartości,
strumieni lub semantyki wymaga nowej wersji protokołu i skoordynowanej migracji
konsumenta. Poniższy rotation sink jest skoordynowaną korektą v1 przed jego
pierwszą akceptacją live; po zaakceptowaniu pierwszego release zmiana tej
semantyki wymaga v2.

### Wywołanie

Manager uruchamia zaakceptowany obraz FPM dokładnie jako:

```text
php artisan platform-access:probe \
  --provider=spotify|youtube \
  --principal=technical|tester \
  --write \
  --format=json \
  --no-ansi \
  --no-interaction
```

`--write` i `--format=json` są wymagane. Dozwolone są wyłącznie providerzy
`spotify` i `youtube` oraz principals `technical` i `tester`. Brakujące
opcje, inne wartości, argumenty pozycyjne lub niewspierana kombinacja kończą się
`invalid-invocation`/exit `2` przed requestem sieciowym. Dla
`technical` obecność `--write` żąda pełnej macierzy akceptacyjnej, ale nie
zezwala na mutację zasobu.

### Wejście `technical`

`technical` czyta wyłącznie poniższe wymagane, niepuste i nieplaceholderowe
wartości runtime:

| Provider | Klucze |
| --- | --- |
| Spotify | `SPOTIFY_CLIENT_ID`, `SPOTIFY_CLIENT_SECRET`, `SPOTIFY_TECHNICAL_REFRESH_TOKEN`, `SPOTIFY_TECHNICAL_EXPECTED_ACCOUNT_ID`, `SPOTIFY_TECHNICAL_ACCOUNT_ID`, `SPOTIFY_TECHNICAL_SCOPES` |
| YouTube | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `YOUTUBE_TECHNICAL_REFRESH_TOKEN`, `YOUTUBE_TECHNICAL_EXPECTED_ACCOUNT_ID`, `YOUTUBE_TECHNICAL_ACCOUNT_ID`, `YOUTUBE_TECHNICAL_SCOPES` |

Dwa identyfikatory konta muszą być równe, a tożsamość zwrócona przez providera
musi być im równa. Scope zapisane jako lista rozdzielona spacjami muszą tworzyć
dokładnie właściwy zbiór:

| Provider | Exact scope set |
| --- | --- |
| Spotify | `playlist-modify-private`, `playlist-read-private`, `user-read-private` |
| YouTube | `https://www.googleapis.com/auth/youtube` |

Probe zawsze wykonuje refresh-token exchange i nie używa istniejącego access
tokenu jako dowodu. Nie czyta sesji ani konfiguracji testera. Obecne w
środowisku access tokeny i metadane lifecycle nie są wejściem probe.

Dla aplikacji Spotify w Development Mode Manager gwarantuje jako zewnętrzny
warunek PaaS aktywne Premium właściciela aplikacji. Probe nie próbuje wywodzić
tego warunku z profilu uwierzytelnionego principal ani z deprecated pola
`product`; mapuje wyłącznie jednoznaczne strukturalne odmowy operacji.

### Wejście `tester`

`tester` czyta wyłącznie dokument UTF-8 JSON z
`/run/secrets/music-map-platform-access/session.json` i nigdy nie korzysta
z technicznych wartości środowiska. Obiekt jest
zamknięty: nieznane pola są zabronione. Opublikowany limit całego dokumentu
sesji wynosi 512 KiB; większy dokument jest odrzucany jako `invalid-session`.

| Pole | Reguła |
| --- | --- |
| `protocol` | wymagane; dokładnie `music-map.platform-access.v1` |
| `provider` | wymagane; `spotify` albo `youtube`; zgodne z opcją komendy |
| `principal` | wymagane; dokładnie `tester` |
| `client_id`, `client_secret`, `refresh_token` | wymagany string 1–8192 bez znaków U+0000–U+0020 |
| `expected_account_id` | wymagany string 1–255 bez znaków U+0000–U+0020 |
| `item_uris` | wymagana tablica dokładnie 3 stringów bez znaków U+0000–U+0020; Spotify wymaga kanonicznego URI `spotify:track:` + dokładnie 22 znaki base62 `[A-Za-z0-9]`, a YouTube dokładnie 11 znaków `[A-Za-z0-9_-]` przekazywanych jako `snippet.resourceId.videoId` |
| `playlist_id` | wymagany dla Spotify i YouTube; string 1–255 bez znaków U+0000–U+0020; wskazuje dedykowaną, prywatną i początkowo pustą playlistę testową właściwego providera |

Brak lub nieczytelność pliku, błędny JSON, dodatkowe pole, niezgodność
protocol/provider/principal albo zła wartość kończą się
`invalid-session`/exit `2` bez requestu sieciowego.

Manager gwarantuje, że zarezerwowana playlista fixture właściwego providera nie
jest używana ani modyfikowana przez innego aktora podczas probe. Aplikacja przed
pierwszą mutacją potwierdza, że playlista jest prywatna, należy do oczekiwanego
konta i jest pusta; inny stan oznacza `fixture-invalid` bez mutacji.

### Prywatny rotation sink

Dla obu principals Manager udostępnia aplikacji zapisywalny, efemeryczny
locator `/run/secrets/music-map-platform-access/replacement.json`. Brak pliku
po probe oznacza, że provider nie zwrócił replacement refresh tokenu. Jeżeli
provider go zwróci, aplikacja przed dalszym autoryzowanym wywołaniem zapisuje
do locatora dokładnie jeden dokument UTF-8 JSON zakończony newline, z polami
wyłącznie `protocol`, `provider`, `principal` i
`refresh_token`. Pierwsze trzy pola muszą odpowiadać wywołaniu, a
`refresh_token` spełnia te same granice 1–8192 co wejście sesji.
Opublikowany limit całego dokumentu replacement wynosi 128 KiB.

Sink jest jedynym sekretnym kanałem wyjściowym probe. Token nie trafia do
stdout, stderr ani logów. Błąd bezpiecznego zapisu kończy się
`refresh-token-rotation-required`/exit `1` bez sukcesu. Po poprawnym zapisie
probe używa otrzymanego access tokenu do dalszej weryfikacji. Manager uznaje
wynik za kompletny dopiero po przejęciu dokumentu: dla `technical` zapisuje
replacement token transakcyjnie w swoim credential store, a dla `tester`
utrzymuje go wyłącznie do zakończenia revoke: dla YouTube odwołuje aktualny
token programistycznie, a dla Spotify usuwa sekret po ręcznym `Remove Access`.
Sposób utworzenia, transportu, przejęcia i usunięcia sinka pozostaje wewnętrzną
odpowiedzialnością PaaS.

### Odpowiedź sukcesu

Sukces zapisuje dokładnie jeden obiekt UTF-8 JSON zakończony newline do stdout
i nic do stderr. Obiekt ma wyłącznie:

| Pole | Wartość |
| --- | --- |
| `protocol` | `music-map.platform-access.v1` |
| `provider` | znormalizowane `spotify` albo `youtube` |
| `principal` | znormalizowane `technical` albo `tester` |
| `status` | `ok` |
| `capabilities` | unikalna tablica stringów w porządku bajtowym |
| `complete` | `true` |

Exact capability sets:

| Principal | Capabilities |
| --- | --- |
| `technical` | `identity`, `read`, `refresh-token-exchange` |
| `tester` | `cleanup`, `identity`, `read`, `refresh-token-exchange`, `write` |

`refresh-token-exchange` wolno zwrócić dopiero po rzeczywistej wymianie
refresh tokenu i co najmniej jednym autoryzowanym wywołaniu API nowym access
tokenem. Exit `0` jest dozwolony dopiero po wszystkich operacjach, w tym
cleanup testera, oraz po bezpiecznym zapisaniu ewentualnego replacement tokenu
do rotation sinka. Manager nie uznaje tego sukcesu, dopóki nie przejmie sinka.

### Odpowiedź błędu i exit codes

Błąd pozostawia stdout pusty i zapisuje do stderr dokładnie jeden obiekt JSON
UTF-8 zakończony newline, nie większy niż 1024 bajty. Obiekt ma wyłącznie:
`protocol`, `provider`, `principal`, `status: error`, `category`,
świeży niepoufny UUIDv4 `correlation_id` i `complete: false`.

Dla `invalid-invocation` pola `provider` i `principal` zawierają poprawną
znormalizowaną wartość albo JSON `null`, gdy wartość jest brakująca lub
nieobsługiwana; surowa błędna wartość nigdy nie jest odbijana. Dla pozostałych
kategorii oba pola są niepuste i zgodne z wywołaniem.

| Exit | Kategoria | Znaczenie |
| ---: | --- | --- |
| 2 | `invalid-invocation` | Niewspierane albo niekompletne opcje komendy. |
| 2 | `invalid-configuration` | Brakująca, placeholderowa, błędnie sformatowana albo wewnętrznie niespójna konfiguracja technical. |
| 2 | `invalid-session` | Brakująca, nieczytelna albo niezgodna ze schematem sesja testera. |
| 1 | `provider-unavailable` | Awaria sieci, timeout albo odpowiedź providera 5xx. |
| 1 | `provider-response-invalid` | Ograniczona odpowiedź providera jest błędnie sformatowana albo niekompletna. |
| 1 | `authorization-denied` | Refresh token albo wynikowa autoryzacja zostały odrzucone. |
| 1 | `account-mismatch` | Tożsamość providera różni się od oczekiwanego konta. |
| 1 | `scope-mismatch` | Zaobserwowana autoryzacja nie ma dokładnie wymaganego zbioru scope'ów. |
| 1 | `account-requirement-failed` | Warunek konta providera, np. Spotify Premium, nie jest spełniony. |
| 1 | `resource-access-denied` | Autoryzowane konto nie może odczytać albo zmienić wymaganego zasobu probe. |
| 1 | `fixture-invalid` | Stan początkowy fixture jest niezgodny z kontraktem albo syntaktycznie poprawny element testera został odrzucony przez providera jako nieużywalny. |
| 1 | `rate-limited` | Provider zgłosił throttling albo HTTP 429. |
| 1 | `quota-exceeded` | Spotify albo YouTube zgłosił strukturalnym kodem wyczerpanie kwoty aplikacji lub projektu. |
| 1 | `refresh-token-rotation-required` | Provider zwrócił replacement refresh token, ale probe nie mógł bezpiecznie zapisać go do prywatnego sinka Managera. |
| 1 | `cleanup-failed` | Przywrócenie pustego stanu fixture Spotify albo YouTube było niekompletne lub którakolwiek mutacja YouTube miała niejednoznaczny wynik; ta kategoria ma pierwszeństwo przed wcześniejszą awarią. |

Nieklasyfikowalna bezpiecznie awaria używa `provider-unavailable`.
`cleanup-failed` ma pierwszeństwo przed wcześniejszą awarią. Nie wolno
dodawać kategorii ani emitować provider-controlled tekstu.

### Semantyka providerów

- Oba principals wymieniają refresh token przy każdym wywołaniu, potwierdzają
  expected account, exact scope oraz przynajmniej jeden autoryzowany odczyt.
- `technical` nie wykonuje mutacji zasobu providera. Jeżeli provider zwróci
  replacement refresh token, probe zapisuje go wyłącznie do prywatnego sinka
  Managera; nie zapisuje go trwale i nie emituje w protokole lub logach.
- Spotify `tester` używa zarezerwowanej, prywatnej i początkowo pustej
  playlisty, zapisuje dokładnie trzy `item_uris`, weryfikuje ich kolejność i w
  `finally` przywraca oraz potwierdza pusty stan początkowy.
- YouTube `tester` używa zarezerwowanej, prywatnej i początkowo pustej
  playlisty, zapisuje dokładnie trzy `item_uris`, weryfikuje ich kolejność i w
  `finally` usuwa elementy oraz potwierdza pusty stan początkowy.
- Nieudane przywrócenie Spotify albo YouTube zwraca
  `cleanup-failed`, nawet jeśli wcześniejszy krok także się nie udał.

## Phase 1: Publiczny protokół i granice wejścia/wyjścia

### Przegląd

Faza utrwala kontrakt jako typowane wartości, zamkniętą walidację i bezpieczne
serializery, zanim powstanie kod wywołujący providerów.

### Wymagane zmiany

#### 1. Konfiguracja i stałe protokołu

**Pliki**: `.env.example`, `config/services.php`,
`app/Integrations/PlatformAccess/PlatformAccessProtocol.php`

**Cel**: Dodać symboliczne technical keys i exact scope bez zmiany istniejącego
`services.google`. Konfiguracja YouTube wskazuje te same klucze klienta Google,
ale osobne tokeny, konto i scope platform-access.

#### 2. Zamknięte wejście

**Pliki**: `app/Integrations/PlatformAccess/ProbeInvocation.php`,
`app/Integrations/PlatformAccess/TesterSession.php`,
`app/Integrations/PlatformAccess/TechnicalConfiguration.php`

**Cel**: Ręcznie i w sposób typowany walidować dokładny kontrakt v1 bez dodatkowej
zależności. Walidacja invocation musi przejąć kontrolę także nad brakującymi,
nieobsługiwanymi i pozycyjnymi argumentami, aby Symfony Console nie wypisało
niezgodnego human-readable błędu. `ProbeInvocation` udostępnia walidację pełnej
listy raw tokenów: pierwszym tokenem musi być dokładnie
`platform-access:probe`, a po nim występują dokładnie po jednym
`--provider=<spotify|youtube>` i `--principal=<technical|tester>` oraz flagi
`--write`, `--format=json`, `--no-ansi`, `--no-interaction`, niezależnie od ich
kolejności. Walidator odrzuca token przed nazwą komendy, duplikaty, formę
rozdzieloną spacją, inne opcje i każdy argument pozycyjny. Integracja tego
walidatora z `ArgvInput` należy do Fazy 3; testy jednostkowe tej fazy sprawdzają
sam zamknięty parser tokenów.

#### 3. Zamknięte wyjście

**Pliki**: `app/Integrations/PlatformAccess/ProbeResult.php`,
`app/Integrations/PlatformAccess/ProbeFailure.php`

**Cel**: Serializować exact success/failure schema, strumienie i newline.
`ProbeFailure` dopuszcza nullable provider/principal wyłącznie dla
`invalid-invocation` i wiąże każdą kategorię z exit `1` albo `2`.

#### 4. Prywatny kanał replacement refresh tokenu

**Plik**: `app/Integrations/PlatformAccess/RefreshTokenRotationSink.php`

**Cel**: Zapisywać opcjonalny replacement refresh token wyłącznie do stałego
locatora PaaS jako zamknięty, ograniczony dokument. Zapis musi zakończyć się
przed dalszym provider call i przed sukcesem; nieobecny albo niezapisywalny sink
przy replacement tokenie daje `refresh-token-rotation-required`. Klasa nie zna
hosta, mountów, journali ani trwałego credential store Managera.

### Kryteria sukcesu

#### Automated Verification

- Zweryfikować zamknięty kontrakt wejścia i wywołania — odrzuca wszystkie niepoprawne dane
  przed siecią; tabela testowa obejmuje brak, duplikat, formę rozdzieloną
  spacją, nieznaną opcję, złą wartość i argument pozycyjny:
  `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeInputTest.php`.
- Zweryfikować zamknięte odpowiedzi i kody wyjścia — mają dokładne pola oraz limit błędu:
  `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeOutputTest.php`.
- Potwierdzić separację principal i brak sieci po błędzie wejścia:
  `php artisan test tests/Unit/Integrations/PlatformAccess/PrincipalBoundaryTest.php`.
- Zweryfikować prywatny rotation sink i brak wycieku replacement tokenu:
  `php artisan test tests/Unit/Integrations/PlatformAccess/RefreshTokenRotationSinkTest.php`.

#### Manual Verification

- Potwierdzić zgodność lokalnego kontraktu z publikacją PaaS — przegląd obejmuje
  invocation, wejścia, odpowiedzi, scope'y, capabilities, kategorie i exit codes
  z opublikowanym v1.

## Phase 2: Probe'y providerów i macierze principal

### Przegląd

Faza implementuje tylko aplikacyjne wywołania Spotify i YouTube wymagane przez
capability v1. OAuth lifecycle i uruchamianie prób pozostają poza repozytorium.

### Wymagane zmiany

#### 1. Wspólny kontrakt probe

**Plik**: `app/Integrations/PlatformAccess/PlatformProbe.php`

**Cel**: Przyjąć wyłącznie zwalidowane wejście i zwrócić capabilities albo
typowane `ProbeFailure`; interface nie przyjmuje źródła konfiguracji ani nie
potrafi przełączać principal. Konkretne probe'y otrzymują
`RefreshTokenRotationSink` jako zależność i zapisują replacement token przed
dalszym autoryzowanym requestem; sam interface nie ujawnia sekretu w wyniku.

#### 2. Spotify

**Plik**: `app/Integrations/PlatformAccess/SpotifyProbe.php`

**Cel**: Dla obu principals wykonać `POST https://accounts.spotify.com/api/token`
z `grant_type=refresh_token`, następnie porównać zwrócony `scope` jako zbiór z
exact Spotify scope i wykonać `GET https://api.spotify.com/v1/me` nowym access
tokenem. Pole `account_id` (nie zmienne `id`) musi być równe
`expected_account_id`. Pole `product`, jeśli mimo deprecjacji występuje w
odpowiedzi, jest ignorowane; wyłącznie strukturalny kod operacji jednoznacznie
wskazujący niespełniony wymóg konta mapuje się na
`account-requirement-failed`. Replacement refresh token jest zapisywany do
prywatnego rotation sinka przed dalszym użyciem access tokenu; błąd zapisu
kończy próbę jako `refresh-token-rotation-required`.

Dla `technical` udane `/me` jest dowodem identity/read i kończy próbę bez
mutacji. Dla `tester` najpierw pobrać metadane z
`GET /v1/playlists/{playlist_id}` oraz pierwszą stronę
`GET /v1/playlists/{playlist_id}/items?limit=1` i potwierdzić przed mutacją, że
playlista jest prywatna, pusta i należy do oczekiwanego konta. Stabilne
`/me.account_id` jest jedyną wartością porównywaną z `expected_account_id`.
Efemeryczne `/me.id` służy wyłącznie do porównania z `playlist.owner.id`, ponieważ
obiekt playlisty nie udostępnia `owner.account_id`; nie jest fallbackiem
tożsamości, nie jest utrwalane i nie trafia do outputu ani logów. Brak
`/me.id`, brak `playlist.owner.id` lub ich różnica daje `fixture-invalid` bez
mutacji. Niepusta albo publiczna playlista również kończy się
`fixture-invalid` bez mutacji. Następnie zastąpić zawartość dokładnie trzema `spotify:track:` URI przez
`PUT /v1/playlists/{playlist_id}/items`, ponownie odczytać i porównać kolejność.
W `finally` wyczyścić playlistę przez `PUT` z pustą tablicą URI, ponownie ją
odczytać i potwierdzić pusty stan. Każda awaria lub niepełna weryfikacja
wyczyszczenia kończy się `cleanup-failed`; pól zmiennych niezależnie od probe,
takich jak `snapshot_id` i liczba followers, nie używa się do porównania stanu.

#### 3. YouTube

**Plik**: `app/Integrations/PlatformAccess/YouTubeProbe.php`

**Cel**: Dla obu principals wykonać `POST https://oauth2.googleapis.com/token`
z `grant_type=refresh_token`, porównać zwrócony `scope` jako zbiór z exact
YouTube scope i wywołać
`GET https://www.googleapis.com/youtube/v3/channels?part=id&mine=true` nowym
access tokenem. Odpowiedź musi jednoznacznie wskazywać jedno konto zgodne z
`expected_account_id`; to wywołanie stanowi dowód identity/read dla
`technical`, który nie mutuje zasobów. Jeżeli refresh response zawiera
replacement refresh token, probe zapisuje go do prywatnego rotation sinka
przed dalszym requestem; błąd zapisu kończy próbę jako
`refresh-token-rotation-required`.

Dla `tester` pobrać zarezerwowaną playlistę przez dokładnie
`GET /youtube/v3/playlists?part=snippet,status&id=<playlist_id>` i wymagać
dokładnie jednego wyniku, `snippet.channelId == expected_account_id` oraz
`status.privacyStatus == private`. Następnie pobrać pierwszą stronę jej
elementów z `maxResults=1` i potwierdzić pusty stan. Każda niezgodność przed
mutacją daje `fixture-invalid` bez mutacji. Dla każdego z dokładnie trzech `item_uris`
wykonać `POST /youtube/v3/playlistItems?part=snippet` z dokładnym body
`{"snippet":{"playlistId":"<playlist_id>","resourceId":{"kind":"youtube#video","videoId":"<item_uri>"}}}`
i zachowywać zwrócony playlist-item ID wyłącznie do cleanup. Testy asertują
dokładne body wszystkich trzech insertów. Następnie wykonać dokładnie jedno
`GET /youtube/v3/playlistItems?part=id,snippet&playlistId=<id>&maxResults=50`:
odpowiedź musi zawierać dokładnie trzy video IDs w kolejności i nie może mieć
`nextPageToken`; dodatkowa strona jest `provider-response-invalid`.

`finally` odczytuje bieżące elementy fixture, usuwa każdy playlist-item przez
`DELETE /youtube/v3/playlistItems?id=<playlist-item-id>`, ponownie odczytuje
fixture i potwierdza pusty stan. Playlista nie jest tworzona ani usuwana przez
probe. Timeout transportu, niejednoznaczna odpowiedź albo 2xx bez wymaganego ID
przy dowolnym `playlistItems.insert/delete` zawsze kończą się
`cleanup-failed`, nawet jeżeli późniejszy bounded odczyt chwilowo pokazuje pustą
playlistę; brak elementu po pojedynczym skanie nie jest dowodem, że opóźniona
mutacja nie zostanie zatwierdzona. Taki wynik wymaga późniejszego, zewnętrznego
potwierdzenia pustej fixture przed kolejnym smoke lub closeoutem. Playlist ID,
playlist-item IDs i odpowiedzi pozostają provider-controlled i nie trafiają do
outputu ani logów. Service account i API-key fallback są niedozwolone.

#### 4. Mapowanie i poufność

**Plik**: `app/Integrations/PlatformAccess/ProviderFailureMapper.php`

**Cel**: Mapować wyłącznie etap operacji, HTTP status i bezpieczny strukturalny
kod/reason odpowiedzi. Transport, timeout i 5xx → `provider-unavailable`; 2xx z
błędnym JSON-em lub brakującymi wymaganymi polami →
`provider-response-invalid`; odrzucenie refresh tokenu albo 401 →
`authorization-denied`; brak lub inny exact scope → `scope-mismatch`; różna
tożsamość → `account-mismatch`; niespełniony wymóg konta Spotify →
`account-requirement-failed`; 403/404 dla playlisty przy poprawnej autoryzacji →
`resource-access-denied`; niezgodny stan początkowy fixture albo strukturalne
odrzucenie jej elementu podczas insertu → `fixture-invalid`; Spotify
`QUOTA_EXCEEDED` oraz YouTube `quotaExceeded` albo `dailyLimitExceeded` →
`quota-exceeded`; pozostałe 429 → `rate-limited`; nieudany zapis replacement
refresh tokenu do prywatnego sinka → `refresh-token-rotation-required`;
nieudany restore/delete → `cleanup-failed`.
Niejednoznaczny 4xx nie jest zgadywany na podstawie tekstu i wpada do
bezpiecznego `provider-unavailable`. Żaden exception message, body, URL, header
ani identyfikator nie trafia do wyniku lub logu.

### Kryteria sukcesu

#### Automated Verification

- Zweryfikować macierze Spotify i exact restore:
  `php artisan test tests/Unit/Integrations/PlatformAccess/SpotifyProbeTest.php`.
- Zweryfikować macierze YouTube i exact restore:
  `php artisan test tests/Unit/Integrations/PlatformAccess/YouTubeProbeTest.php`.
- Potwierdzić zamknięte mapowanie błędów i poufność:
  `php artisan test tests/Unit/Integrations/PlatformAccess/ProviderFailureMapperTest.php`;
  każda z 12 provider-facing kategorii publicznego kontraktu ma co najmniej
  jeden deterministyczny przypadek, a canary provider-controlled text nigdy nie
  opuszcza adaptera; trzy błędy wejścia pokrywa Faza 1.
- Potwierdzić brak regresji logowania Google:
  `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php`.

#### Manual Verification

- Potwierdzić granicę PaaS i semantykę cleanup — przegląd obejmuje brak OAuth
  lifecycle, Manager internals, fallbacków i mutacji dla `technical` oraz
  obowiązkowy cleanup dla `tester`.

## Phase 3: Komenda, źródło i akceptacja interoperacyjna

### Przegląd

Faza składa adapter w jeden entrypoint, chroni jego obecność w obrazie i
przygotowuje zreviewowany, zielony artefakt do przekazania zewnętrznemu PaaS.
Promocja release, OAuth i live rehearsal należą do planu Managera.

### Wymagane zmiany

#### 1. Entrypoint

**Pliki**: `artisan`, `app/Console/Commands/ProbePlatformAccess.php`

**Cel**: Udostępnić exact signature `platform-access:probe`, wybrać probe po
providerze bez fallbacku i wykonać opisany w Fazie 1 raw-argv preflight przed
rozwiązywaniem nazwy komendy przez Symfony. Front controller `artisan`, po
załadowaniu autoloadera i aplikacji, lecz przed
`$app->handleCommand(new ArgvInput())`, pobiera pełne
`ArgvInput::getRawTokens(false)`. Standardowe meta-wywołanie
`help platform-access:probe` przepuszcza bez zmian. Dla pozostałych list
zawierających dokładny token `platform-access:probe` przekazuje całość do
wspólnego `ProbeInvocation`; błąd renderuje przez wspólny serializer jako
jedyny JSON na stderr i kończy exit `2`, bez wejścia do Console Kernel. Dzięki
temu także
nieopcyjny token albo wartość nieznanej opcji przed nazwą komendy nie zostaje
uznany przez Symfony za inną komendę i nie powoduje human-readable outputu.

Po udanym preflighcie `artisan` przekazuje ten sam `ArgvInput` do aplikacji.
`ProbePlatformAccess` korzysta ze standardowego bindu i typowanej walidacji;
dla programowego `ArrayInput` zachowuje tę samą walidację wartości bez założenia
o `getRawTokens()`. Komenda zwraca exact JSON na właściwym strumieniu i kończy
kodem z `ProbeResult` albo `ProbeFailure`. Jej zewnętrzna granica przechwytuje
każdy nieobsłużony `Throwable`, nie przekazuje go do Kernela ani loggera i
renderuje pojedyncze `provider-unavailable`. Parser i serializer nie są
duplikowane w `artisan`.

#### 2. Test komendy

**Plik**: `tests/Feature/Console/ProbePlatformAccessTest.php`

**Cel**: Pokryć cztery poprawne kombinacje provider×principal, invalid
invocation/session/configuration, exact capabilities, stdout/stderr/newline,
exit codes i brak jakiegokolwiek requestu po błędzie wejścia. Testy adaptera
mogą używać Laravel HTTP fake, ale macierz niepoprawnych surowych tokenów musi
uruchamiać prawdziwy subprocess `php artisan` z timeoutem i osobnym przechwyceniem
stdout/stderr. Macierz zawiera nieznaną opcję zarówno przed, jak i po nazwie
komendy, nieopcyjny token przed nazwą oraz parę
`--unknown separate-value` przed nazwą. Potwierdza też standardowy wynik
`php artisan help platform-access:probe`, zachowanie innych komend Artisan oraz
to, że poprawne programowe `ArrayInput` nie próbuje
wywołać `getRawTokens()`. Dzięki temu testuje front controller, etap
rozwiązywania komendy i bind Symfony, którego `artisan()` ani
`ApplicationTester` z `ArrayInput` nie odwzorowują.
Test in-process używa kontrolowanego bindingu, aby wymusić nieoczekiwany wyjątek
po skutecznym dispatchu wewnątrz komendy, i potwierdza pojedynczy kontraktowy
JSON z newline na stderr, pusty stdout oraz brak przekazania wyjątku do loggera.
Prawdziwy subprocess pozostaje wymagany dla raw argv i dokładnego rozdzielenia
strumieni na ścieżkach niewymagających testowego fault injection.

#### 3. Kontrakt źródła i dokumentacja

**Pliki**: `scripts/verify-source-contract`,
`tests/Unit/SourceContractTest.php`, `README.md`

**Cel**: Dodać każdy stabilny plik subsystemu do manifestu źródła i opisać
wyłącznie entrypoint oraz publiczną granicę. Artefakty
`context/changes/*` nie trafiają do `required_paths`. Rozszerzyć
`scripts/verify-source-contract`, aby po sprawdzeniu jawnego manifestu
enumerował wszystkie pliki PHP pod `app/` i `tests/` oraz kończył się błędem,
gdy którykolwiek nie występuje w `required_paths`; dzięki temu nowy pominięty
plik nie może przejść trybu `--worktree`. `SourceContractTest` chroni tę regułę
przed usunięciem.

### Kryteria sukcesu

#### Automated Verification

- Zweryfikować exact kontrakt komendy:
  `php artisan test tests/Feature/Console/ProbePlatformAccessTest.php`.
- Uruchomić pełne bramki repozytorium bez sieci i sekretów:
  `composer test && vendor/bin/pint --test && npm run build`.
- Zweryfikować kompletny manifest źródła bez artefaktów lifecycle:
  `sh scripts/verify-source-contract --worktree`; negatywny przypadek testowy
  dowodzi, że dodatkowy plik PHP pod `app/` lub `tests/`, którego nie ma w
  `required_paths`, powoduje błąd.

#### Manual Verification

- Potwierdzić gotowość artefaktu do przekazania PaaS — exact commit przechodzi
  literalny przegląd kontraktu i wszystkie bramki repozytorium; promocja, OAuth
  i live acceptance pozostają wyłącznie w planie Managera.

## Strategia testowania

- Testy wejścia generują brakujące, dodatkowe, błędnie typowane i graniczne
  pola, w tym nullable provider/principal tylko dla `invalid-invocation`;
  osobna tabela subprocess sprawdza pełne raw argv przed bindowaniem Symfony,
  w tym nieznaną opcję przed i po nazwie komendy.
- HTTP fake pokrywa timeout, 401, 403, 429, 5xx, błędny JSON, account/scope
  mismatch, quota, rotation-required oraz awarię cleanup.
- Canary secrets, PII i identyfikatory są wstrzykiwane do requestów, odpowiedzi
  i wyjątków; asercje potwierdzają ich brak w stdout, stderr i logach.
- Testy replacement tokenu potwierdzają exact dokument prywatnego sinka,
  przerwanie przed dalszym requestem przy błędzie zapisu oraz brak sekretu w
  stdout, stderr, logach i trwałych plikach aplikacji.
- Testy ustawiają spy loggera i potwierdzają zero interakcji dla każdej ścieżki
  probe; kontrolowany test in-process catch-all dowodzi również, że wyjątek nie
  trafia do standardowego raportowania Laravel. Subprocess nie wymaga osobnego
  mechanizmu fault injection.
- Testy cleanup obu providerów obejmują niepustą, cudzą lub publiczną fixture
  bez mutacji, awarię po zapisie i podczas czyszczenia oraz potwierdzenie
  powrotu do pustego stanu. Spotify osobno porównuje `/me.account_id` z
  `expected_account_id` oraz efemeryczne `/me.id` z `playlist.owner.id`, w tym
  brak i mismatch obu pól właściciela. YouTube dodatkowo dowodzi, że timeout albo
  niejednoznaczna odpowiedź z `playlistItems.insert/delete` zawsze daje
  `cleanup-failed`, nawet gdy późniejszy bounded odczyt nie widzi elementu;
  awaria cleanup zawsze wygrywa kategorią.
- Test regresyjny Google chroni współdzielone client ID/secret i niezależny
  redirect logowania.

## Uwagi dotyczące wydajności

Probe jest jednorazowym narzędziem akceptacyjnym, nie ścieżką requestu
użytkownika. Nie wykonuje retry. Odczyty są ograniczone do danych koniecznych
do identity/read, a tester zapisuje dokładnie trzy elementy. Mechanizm limitu
pięciu operacji YouTube dziennie należy do późniejszego przepływu produktu,
nie do tego probe.

## Uwagi dotyczące migracji

Zmiana nie modyfikuje bazy danych ani zapisanych tożsamości użytkowników.
Rollback kodu usuwa entrypoint wraz z obrazem; rollback i recovery poświadczeń
pozostają odpowiedzialnością Managera. Rotation sink jest korektą kontraktu v1
przed jego pierwszą akceptacją live. Po pierwszym accepted release zmiana jego
schematu lub semantyki wymaga v2 i migracji obu stron.

## Referencje

- Zakres produktu: `context/foundation/prd.md`.
- Kolejność i zależności: `context/foundation/roadmap.md`.
- Publiczna granica PaaS: `AGENTS.md`.
- Wzorzec bezpiecznego logowania: `app/Http/Controllers/Auth/GoogleAuthController.php`.
- Konfiguracja Google login: `config/services.php`.
- Kontrakt source manifest: `scripts/verify-source-contract`.
- Spotify Web API: refresh token, `/me`, playlist items, replace i add items:
  `https://developer.spotify.com/documentation/web-api/tutorials/refreshing-tokens`,
  `https://developer.spotify.com/documentation/web-api/tutorials/february-2026-migration-guide`,
  `https://developer.spotify.com/blog/2026-07-23-web-api-quota-updates`,
  `https://developer.spotify.com/documentation/web-api/reference/get-current-users-profile`,
  `https://developer.spotify.com/documentation/web-api/reference/get-playlists-items`,
  `https://developer.spotify.com/documentation/web-api/reference/reorder-or-replace-playlists-items`,
  `https://developer.spotify.com/documentation/web-api/reference/add-items-to-playlist`.
- YouTube Data API: `channels.list`, `playlists.list` oraz
  `playlistItems.insert/list/delete`:
  `https://developers.google.com/youtube/v3/docs/channels/list`,
  `https://developers.google.com/youtube/v3/docs/playlists/list`,
  `https://developers.google.com/youtube/v3/docs/playlistItems/insert`,
  `https://developers.google.com/youtube/v3/docs/playlistItems/list`,
  `https://developers.google.com/youtube/v3/docs/playlistItems/delete`.
- Google OAuth refresh-token exchange i pole `scope` odpowiedzi:
  `https://developers.google.com/identity/protocols/oauth2/web-server#offline`.

## Progress

> Konwencja: `- [ ]` oczekujące, `- [x]` wykonane. Dodaj ` — <commit sha>` po wylądowaniu kroku. Nie zmieniaj nazw tytułów kroków.

### Phase 1: Publiczny protokół i granice wejścia/wyjścia

#### Automated

- [x] 1.1 Zweryfikować zamknięty kontrakt wejścia i wywołania — 2931c26
- [x] 1.2 Zweryfikować zamknięte odpowiedzi i kody wyjścia — 2931c26
- [x] 1.3 Potwierdzić separację principal i brak sieci po błędzie wejścia — 2931c26
- [x] 1.5 Zweryfikować prywatny rotation sink i brak wycieku replacement tokenu — 2931c26

#### Manual

- [x] 1.4 Potwierdzić zgodność lokalnego kontraktu z publikacją PaaS — 2931c26

### Phase 2: Probe'y providerów i macierze principal

#### Automated

- [ ] 2.1 Zweryfikować macierze Spotify i exact restore
- [ ] 2.2 Zweryfikować macierze YouTube i exact restore
- [ ] 2.3 Potwierdzić zamknięte mapowanie błędów i poufność
- [ ] 2.4 Potwierdzić brak regresji logowania Google

#### Manual

- [ ] 2.5 Potwierdzić granicę PaaS i semantykę cleanup

### Phase 3: Komenda, źródło i akceptacja interoperacyjna

#### Automated

- [ ] 3.1 Zweryfikować exact kontrakt komendy
- [ ] 3.2 Uruchomić pełne bramki repozytorium
- [ ] 3.3 Zweryfikować kompletny manifest źródła

#### Manual

- [ ] 3.4 Potwierdzić gotowość artefaktu do przekazania PaaS
