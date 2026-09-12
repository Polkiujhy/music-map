# Prywatne konto i wejście do banku playlist — plan implementacji

## Przegląd

Zmiana dostarcza pierwszy pionowy wycinek produktu: publiczne wejście do `music-map`, kompletne konto e-mailowe, logowanie przez Google oraz chroniony, początkowo pusty bank playlist. Obie metody logowania wskazują jedno konto aplikacji po znormalizowanym i zweryfikowanym adresie e-mail, a konflikty tożsamości kończą się bezpieczną odmową bez przepinania danych.

Implementacja selektywnie przenosi wzorce oficjalnego Livewire Starter Kit do istniejącego repozytorium. Nie wolno ponownie scaffoldować projektu ani nadpisywać jego obrazu produkcyjnego, CI i kontraktów `s-manager`.

## Analiza stanu obecnego

Repozytorium jest działającym szkieletem Laravel 13, ale ma tylko publiczną trasę `/` i startowy ekran frameworka. Sesyjny guard, Eloquent provider, tabele `users`, `password_reset_tokens` oraz `sessions` już istnieją, lecz brakuje tras, kontrolerów, widoków i zależności realizujących przepływy uwierzytelniania.

Model `User` zakłada obecnie obowiązkowe hasło. To wyklucza poprawny model kont Google-only bez sztucznego sekretu. Nie istnieje również osobny zapis stabilnego identyfikatora Google, więc samo wyszukiwanie użytkownika po bieżącym e-mailu dostawcy nie wystarczy do bezpiecznego, idempotentnego logowania.

Produkcja korzysta z PostgreSQL zarządzanego przez `/srv/manager`. Aktywny baseline zawiera już migrację tworzącą `users`; tabela nie może być tworzona ręcznie. Każda nowa zmiana schematu musi pozostać addytywna i przejść przez kontrolowany `s-manager schema-release` po backupie oraz isolated restore test.

### Kluczowe odkrycia

- PRD wymaga jednego konta `music-map` dla logowania e-mailem i zewnętrznego dostawcy o tym samym adresie oraz widoczności banku wyłącznie dla właściciela (`context/foundation/prd.md:69`, `context/foundation/prd.md:73`).
- Roadmapa konkretyzuje Google, pusty prywatny bank i ryzyko duplikacji tożsamości jako zakres S-01 (`context/foundation/roadmap.md:94`).
- Repo ma jedynie sesyjny guard i Eloquent provider; fundament frameworka można zachować (`config/auth.php:18`, `config/auth.php:40`, `config/auth.php:64`).
- `users.email` jest unikalny, ale `users.password` jest wymagany (`database/migrations/0001_01_01_000000_create_users_table.php:14`).
- Model użytkownika już hashuje hasła i ukrywa poświadczenia, lecz weryfikacja e-maila nie jest włączona (`app/Models/User.php:5`, `app/Models/User.php:13`, `app/Models/User.php:25`).
- Produkcyjne sesje są bazodanowe, szyfrowane, Secure, HttpOnly i SameSite=Lax, co jest zgodne z przekierowaniem OAuth (`.env.example:33`).
- Aktualne CI uruchamia Pint, PHPUnit, audyty zależności i build Vite, ale PHPUnit korzysta tylko z SQLite (`.github/workflows/ci.yml:39`, `phpunit.xml:20`).
- Trwała decyzja stosu wskazuje Livewire Starter Kit i Flux UI, mimo że zależności nie zostały zainstalowane (`context/foundation/tech-stack.md:22`, `composer.json:8`).
- Zwykły deploy nie wykonuje migracji, a zmiana fingerprintu schematu wymaga nadzorowanego wydania schematu (`context/foundation/infrastructure.md:70`, `context/foundation/infrastructure.md:92`).

## Pożądany stan końcowy

- Gość widzi polski, responsywny landing `music-map` z jasnym opisem produktu i wejściami do rejestracji oraz logowania.
- Użytkownik może zarejestrować konto e-mailem, zweryfikować adres, zalogować się, opcjonalnie użyć „Zapamiętaj mnie”, wylogować się i odzyskać hasło.
- Użytkownik może zalogować się przez Google. Zweryfikowany adres Google tworzy nowe konto albo bezpiecznie dopina metodę do istniejącego konta o tym samym znormalizowanym e-mailu.
- Identyfikator Google przypisany do innego konta, brak potwierdzonego e-maila lub uszkodzony callback nie zmienia żadnych danych i prowadzi do czytelnej, niesensytywnej informacji o odmowie.
- Zweryfikowany użytkownik wchodzi na `/bank`; gość trafia do logowania, a użytkownik bez weryfikacji do ekranu weryfikacji.
- Pusty bank informuje, że dane będą prywatne, ale nie udostępnia martwego formularza ani aktywnego przycisku importu przed S-02.
- Pełna macierz testów przechodzi na SQLite, a krytyczna macierz auth/tożsamości i migracje przechodzą również na jednorazowym PostgreSQL w CI.

## Czego NIE robimy

- Nie tworzymy jeszcze tabel, modeli, polityk ani rekordów playlist; ich właściciel i izolacja rekordów należą do S-02.
- Nie implementujemy importu, edycji, synchronizacji ani eksportu playlist.
- Nie dodajemy ustawień profilu, zmiany adresu e-mail, usuwania konta, 2FA, passkeys ani rozbudowanych ról.
- Nie łączymy w tej zmianie kont Spotify lub YouTube; tabela tożsamości logowania pozostaje oddzielona od przyszłych integracji streamingowych.
- Nie przechowujemy tokenu dostępowego ani odświeżającego Google po zakończeniu callbacku.
- Nie używamy WorkOS ani własnej implementacji protokołu OAuth.
- Nie tworzymy ręcznie tabeli `users` i nie uruchamiamy kandydackich migracji na produkcji jako testu.
- Nie przebudowujemy obecnego CI, obrazów Docker ani mechanizmu wdrożenia poza dodaniem izolowanej bramki PostgreSQL i wymaganych zależności aplikacji.

## Podejście do implementacji

Auth e-mailowy zostanie oparty na Laravel Fortify, a warstwa serwerowego UI na Livewire 4 i Flux UI zgodnie z trwałą decyzją stosu. Z oficjalnego starter kitu należy przenieść tylko potrzebne wzorce, komponenty i konfigurację; istniejące repo nie może zostać zastąpione zawartością startera.

Google OAuth obsłuży Laravel Socialite. Aplikacja będzie przechowywać stabilne powiązanie `(provider, provider_user_id)` w osobnej tabeli `auth_identities`, bez tokenów dostawcy. E-mail będzie normalizowany przez trim i lowercase we wszystkich wejściach, a tworzenie lub łączenie użytkownika wykona pojedyncza transakcja odporna na ponowiony callback i naruszenie unikalności.

`/bank` będzie kanoniczną, nazwaną trasą `bank.index` pod middleware `auth` i `verified`. W tej zmianie bank jest pustym widokiem, nie zasobem danych; S-02 dołoży encje playlist oraz egzekwowanie właściciela na poziomie zapytań i policy.

## Krytyczne szczegóły implementacji

### Sekwencjonowanie stanu

Callback Google najpierw musi potwierdzić stan OAuth, stabilny identyfikator dostawcy i zweryfikowany adres e-mail, a dopiero potem w jednej transakcji znaleźć lub utworzyć użytkownika oraz powiązanie. Reguła decyzji jest jednoznaczna: istniejący subject i to samo konto oznacza logowanie; istniejący subject oraz e-mail wskazujący inne istniejące konto oznacza bezpieczną odmowę; brak subjectu i istniejące konto o znormalizowanym e-mailu oznacza dopięcie identity; oba nowe oznaczają utworzenie verified passwordless user. Istniejącego powiązania nie wolno przepinać na podstawie zmienionego e-maila; konflikt kończy się rollbackiem całej transakcji.

### Czas i cykl życia

Zmiana schematu musi być zgodna zarówno z bieżącym, jak i kandydackim obrazem aplikacji: nullable `password` oraz nowa tabela są rozszerzeniem, a stary kod nadal może działać. Produkcyjny `schema-release` następuje przed wdrożeniem obrazu wymagającego nowej tabeli, nigdy podczas zwykłego reconciliatora.

## Faza 1: Fundament auth i model tożsamości

### Przegląd

Faza wprowadza brakujące zależności oraz addytywny model danych, na którym oprą się oba sposoby logowania. Kończy się testowalnym kontraktem domenowym użytkownika i jego zewnętrznych metod uwierzytelniania.

### Wymagane zmiany

#### 1. Zależności i bootstrapping auth

**Pliki**: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.js`, `bootstrap/providers.php`

**Cel**: Dodać Laravel Fortify, Livewire 4, Flux UI i Laravel Socialite w wersjach zgodnych z Laravel 13 oraz zarejestrować provider konfigurujący auth. Zachować istniejące skrypty, zależności infrastrukturalne i Vite 8.

**Kontrakt**: Repo instaluje zależności z lockfile bez skryptów scaffoldingu nadpisujących pliki; Vite nadal buduje `resources/css/app.css` i `resources/js/app.js`, a Fortify rejestruje tylko funkcje wybrane w tym planie.

#### 2. Konfiguracja Fortify i Google

**Pliki**: `config/fortify.php`, `config/services.php`, `.env.example`, `app/Providers/FortifyServiceProvider.php`

**Cel**: Skonfigurować e-mail jako znormalizowaną nazwę użytkownika, przekierowanie do `/bank`, wymagane widoki, limity prób oraz dane klienta Google bez utrwalania sekretów w repozytorium.

**Kontrakt**: Fortify włącza registration, reset passwords i email verification, ale wyłącza 2FA i passkeys. `services.google` czyta `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` oraz dokładny callback z env; `.env.example` zawiera wyłącznie bezpieczne placeholdery.

#### 3. Addytywna migracja użytkownika

**Plik**: `database/migrations/2026_09_12_000000_make_users_password_nullable.php`

**Cel**: Pozwolić, aby konto utworzone wyłącznie przez Google nie miało lokalnego hasła i nie wymagało sztucznego sekretu.

**Kontrakt**: Kolumna `users.password` staje się nullable bez usuwania lub przekształcania istniejących wartości. Migracja `down` przywraca NOT NULL dopiero po udowodnieniu, że nie istnieją rekordy z NULL; produkcyjny rollback aplikacji nie uruchamia automatycznie `down`.

#### 4. Model powiązań tożsamości

**Pliki**: `database/migrations/2026_09_12_000001_create_auth_identities_table.php`, `app/Models/AuthIdentity.php`, `app/Models/User.php`, `database/factories/AuthIdentityFactory.php`

**Cel**: Zapisać stabilny identyfikator Google oddzielnie od przyszłych połączeń kont streamingowych i włączyć obowiązkową weryfikację e-maila na użytkowniku.

**Kontrakt**: `auth_identities` zawiera `user_id` z cascade delete, `provider`, `provider_user_id` i timestamps; unikalne są pary `(provider, provider_user_id)` oraz `(user_id, provider)`. Model nie ma pól tokenów. `User` implementuje `MustVerifyEmail`, dopuszcza nullable password i udostępnia relację `authIdentities()`.

#### 5. Reguły danych użytkownika

**Pliki**: `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Fortify/PasswordValidationRules.php`, `database/factories/UserFactory.php`

**Cel**: Zcentralizować normalizację e-maila, walidację i hashowanie hasła dla przepływów e-mailowych oraz zapewnić fabryki dla użytkowników lokalnych i Google-only.

**Kontrakt**: Każdy e-mail przed wyszukaniem i zapisem przechodzi trim oraz lowercase. Rejestracja odrzuca duplikaty, w tym warianty wielkości liter; reset hasła nie tworzy duplikatu. Factory ma jawne stany unverified i passwordless zamiast zmieniać domyślne zachowanie istniejących testów.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Zależności instalują się z obu lockfile, a aplikacja uruchamia się bez błędów providerów: `composer install --no-interaction && npm ci --ignore-scripts`.
- Migracje stosują się od zera i cofają na jednorazowej bazie SQLite: `php artisan migrate:fresh --force && php artisan migrate:rollback --force`.
- Testy modelu potwierdzają nullable password, relacje, cascade delete, oba ograniczenia unikalności i brak przechowywania tokenów: `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php`.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Przegląd migracji potwierdza zgodność expand/contract z bieżącym obrazem produkcyjnym i brak ręcznych operacji SQL.
- Przegląd konfiguracji potwierdza, że żaden sekret ani token Google nie trafił do repozytorium, logów lub tabel.

**Uwaga implementacyjna**: Po zakończeniu fazy i automatycznej weryfikacji zatrzymaj się na ręczne potwierdzenie kontraktu migracji oraz obsługi sekretów przed Fazą 2.

---

## Faza 2: Kompletny przepływ e-mailowy

### Przegląd

Faza dostarcza rejestrację, weryfikację adresu, logowanie z opcją zapamiętania, wylogowanie i reset hasła w dostępnych widokach zgodnych z Flux UI.

### Wymagane zmiany

#### 1. Layout i komponenty auth

**Pliki**: `resources/views/layouts/auth.blade.php`, `resources/views/components/app-logo.blade.php`, `resources/views/components/auth-header.blade.php`, `resources/views/components/input-error.blade.php`, `resources/css/app.css`, `resources/js/app.js`

**Cel**: Utworzyć spójny, responsywny szkielet ekranów auth z polskimi komunikatami, widocznym fokusem, pełnymi etykietami i obsługą błędów.

**Kontrakt**: Każdy dokument ma pojedynczy `main`, link pomijający nawigację, właściwą hierarchię nagłówków i błędy skojarzone z polami przez `aria-describedby`; dekoracyjne grafiki są ukryte przed czytnikami.

#### 2. Widoki cyklu konta

**Pliki**: `resources/views/pages/auth/login.blade.php`, `resources/views/pages/auth/register.blade.php`, `resources/views/pages/auth/forgot-password.blade.php`, `resources/views/pages/auth/reset-password.blade.php`, `resources/views/pages/auth/verify-email.blade.php`

**Cel**: Udostępnić wszystkie zatwierdzone przepływy e-mailowe bez ustawień profilu i dodatkowych funkcji konta.

**Kontrakt**: Formularze POST korzystają z tras Fortify i CSRF; login ma opcjonalne „Zapamiętaj mnie”. Rejestracja prowadzi do weryfikacji, reset nie ujawnia istnienia adresu, a użytkownik może ponowić wysłanie weryfikacji w granicach throttlingu.

#### 3. Polskie komunikaty auth

**Pliki**: `lang/pl/auth.php`, `lang/pl/passwords.php`, `lang/pl/validation.php`, `config/app.php`, `.env.example`

**Cel**: Zapewnić spójny język ekranów, walidacji i wiadomości wysyłanych podczas zakładania oraz odzyskiwania konta.

**Kontrakt**: Polski jest podstawowym locale aplikacji, angielski pozostaje fallbackiem; teksty błędów nie ujawniają, czy konto istnieje, poza standardową walidacją rejestracji duplikatu.

#### 4. Testy e-mailowego auth

**Pliki**: `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/AuthenticationTest.php`, `tests/Feature/Auth/EmailVerificationTest.php`, `tests/Feature/Auth/PasswordResetTest.php`, `tests/Feature/Auth/SessionSecurityTest.php`

**Cel**: Utrwalić kompletny kontrakt auth, w tym zachowania bezpieczeństwa, normalizację e-maila i trwałą sesję tylko po świadomym wyborze.

**Kontrakt**: Testy używają `RefreshDatabase` i fake notifications; obejmują happy paths, błędne dane, wariant wielkości liter, unverified access, wygasły lub błędny token, throttling, regenerację sesji przy logowaniu oraz unieważnienie sesji i tokenu CSRF przy wylogowaniu.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełna macierz e-mailowego auth przechodzi: `php artisan test tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/Auth/EmailVerificationTest.php tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/SessionSecurityTest.php`.
- Użytkownicy o adresach różniących się spacjami lub wielkością liter nie mogą utworzyć dwóch kont, a logowanie rozpoznaje znormalizowany adres.
- Testy potwierdzają limit prób logowania, neutralny reset hasła, regenerację sesji i rozróżnienie sesji zwykłej od „Zapamiętaj mnie”.
- Widoki kompilują się w produkcyjnym buildzie: `npm run build`.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Rejestracja, wiadomość weryfikacyjna, kliknięcie linku, logowanie, wylogowanie i reset hasła działają w przeglądarce z testową skrzynką pocztową.
- Formularze są używalne klawiaturą, mają widoczny fokus i czytelne błędy na małym oraz dużym ekranie.
- Na współdzielonym urządzeniu brak zaznaczenia „Zapamiętaj mnie” nie pozostawia trwałego logowania po wygaśnięciu sesji.

**Uwaga implementacyjna**: Po zakończeniu fazy zatrzymaj się na ręczne przejście całego cyklu e-mailowego przed dodaniem Google.

---

## Faza 3: Logowanie i łączenie przez Google

### Przegląd

Faza dodaje bezpośredni OAuth przez Google oraz transakcyjne, idempotentne łączenie metody logowania z jednym kontem `music-map`.

### Wymagane zmiany

#### 1. Serwis rozwiązywania tożsamości

**Pliki**: `app/Services/Auth/ResolveGoogleIdentity.php`, `app/Exceptions/Auth/IdentityConflictException.php`

**Cel**: Oddzielić reguły tworzenia i łączenia konta od kontrolera callbacku oraz jawnie modelować bezpieczną odmowę.

**Kontrakt**: Kontroler przekazuje do serwisu wyłącznie niepusty stabilny Google subject, nazwę i e-mail, gdy surowy payload Google zawiera boolean `email_verified === true` (dla przypiętej wersji Socialite można jawnie obsłużyć udokumentowaną kompatybilność `verified_email === true`); brak pola, `false` lub wartość innego typu oznacza odmowę. W transakcji serwis stosuje regułę decyzji z „Sekwencjonowania stanu”: istniejące `(google, subject)` loguje właściciela tylko wtedy, gdy znormalizowany e-mail wskazuje tego samego użytkownika albo nie wskazuje żadnego innego; brak subjectu dopina użytkownika o znormalizowanym e-mailu albo tworzy nowy verified, passwordless user. Przy dopięciu do istniejącego użytkownika z pustym `email_verified_at` serwis ustawia ten znacznik w tej samej transakcji, ponieważ Google potwierdził ten sam znormalizowany e-mail. Subject wskazujący inne konto, drugie Google identity użytkownika lub naruszenie reguł kończy się `IdentityConflictException` bez częściowego zapisu. Po `UniqueConstraintViolationException` serwis ponawia odczyt w świeżej krótkiej transakcji i ponownie stosuje tę samą regułę; jeśli stan nadal jest konfliktowy, odmawia bez zmian. Nazwa istniejącego użytkownika nie jest nadpisywana.

#### 2. Trasy i kontroler OAuth

**Pliki**: `routes/web.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`

**Cel**: Dodać wejście do Google i callback zachowujący ochronę state/CSRF oraz bezpieczne komunikaty błędów.

**Kontrakt**: Nazwane trasy `auth.google.redirect` i `auth.google.callback` działają w sesyjnym middleware web dla gości i nie używają `stateless()`. Callback wymaga potwierdzonego e-maila według reguły `email_verified` z serwisu tożsamości, loguje rozwiązanego użytkownika, regeneruje sesję i kieruje do `bank.index`. Odmowa, anulowanie, brakujący lub błędny state, błąd providera lub brak danych wraca do logowania bez logowania PII, tokenów i surowej odpowiedzi.

#### 3. Wejścia Google w auth UI

**Pliki**: `resources/views/pages/auth/login.blade.php`, `resources/views/pages/auth/register.blade.php`

**Cel**: Udostępnić tę samą metodę Google na ekranie logowania i rejestracji bez sugerowania, że tworzy osobny typ konta.

**Kontrakt**: Oba przyciski prowadzą do tej samej nazwanej trasy; treść wyjaśnia, że ten sam zweryfikowany e-mail zostanie połączony z istniejącym kontem.

#### 4. Testy OAuth i konfliktów

**Pliki**: `tests/Feature/Auth/GoogleAuthenticationTest.php`, `tests/Unit/Services/Auth/ResolveGoogleIdentityTest.php`

**Cel**: Pokryć główne ryzyko S-01 bez wywoływania prawdziwego API Google.

**Kontrakt**: Socialite fake pokrywa redirect, nowe konto, automatyczne powiązanie istniejącego konta, oznaczenie istniejącego e-maila jako verified, ponowiony callback, zmianę e-maila po istniejącym subject wskazującym inne konto oraz odmowę dla niezweryfikowanego/brakującego e-maila i konfliktu identity. Testy kontrolera potwierdzają, że redirect zapisuje state w sesji, a callback z brakującym lub niezgodnym state kończy się przed pobraniem danych providera i przed resolverem, wraca do logowania oraz nie zmienia bazy. Testy obejmują `email_verified` o wartościach `true`, `false`, brakującej i nietekstowej. Testy serwisu symulują kolizje obu ograniczeń unikalności i potwierdzają ponowny odczyt prowadzący do jednego użytkownika i jednej identity albo do atomowej odmowy. Każda odmowa pozostawia liczbę użytkowników i identities bez zmian.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy serwisu i callbacku Google przechodzą bez sieci: `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php tests/Unit/Services/Auth/ResolveGoogleIdentityTest.php`.
- Ponowienie tego samego callbacku pozostawia jednego użytkownika i jedno powiązanie Google.
- Konto e-mailowe i Google o tym samym znormalizowanym, zweryfikowanym adresie kończą jako jedno konto, a Google weryfikuje wcześniej niezweryfikowany adres.
- Konflikt subject, brak zweryfikowanego e-maila i awaria providera nie tworzą ani nie przepinają rekordów.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Prawdziwy testowy klient Google przechodzi redirect i callback pod skonfigurowanym adresem HTTPS.
- Anulowanie zgody oraz callback z błędem pokazują bezpieczny komunikat i pozwalają wrócić do logowania.
- Logi aplikacji nie zawierają kodu autoryzacyjnego, tokenu, sekretu klienta ani pełnej odpowiedzi Google.

**Uwaga implementacyjna**: Po zakończeniu fazy zatrzymaj się na ręczny test prawdziwego klienta Google przed podłączeniem wejść na landingu.

---

## Faza 4: Publiczne wejście i prywatny bank

### Przegląd

Faza zastępuje ekran Laravela produktem `music-map` i dodaje chroniony, informacyjny pusty bank jako stabilny punkt wejścia dla kolejnych wycinków roadmapy.

### Wymagane zmiany

#### 1. Routing produktu

**Plik**: `routes/web.php`

**Cel**: Zachować publiczny landing i zdefiniować kanoniczny bank dostępny tylko zweryfikowanemu użytkownikowi.

**Kontrakt**: `/` jest nazwaną trasą `home`; `GET /bank` jest nazwane `bank.index` i używa `auth` oraz `verified`. Wszystkie udane przepływy auth kierują do `bank.index`; nie powstaje alias `/dashboard`.

#### 2. Landing music-map

**Plik**: `resources/views/welcome.blade.php`

**Cel**: Zastąpić treści onboardingowe Laravela krótkim opisem niezależnego banku playlist oraz właściwymi CTA.

**Kontrakt**: Gość widzi rejestrację i logowanie, a zalogowany użytkownik wejście do banku. Widok jest responsywny, dostępny klawiaturą, nie obiecuje gotowego importu i używa nazwanych tras zamiast hard-coded URL.

#### 3. Pusty bank

**Pliki**: `resources/views/layouts/app.blade.php`, `resources/views/bank/index.blade.php`, `resources/views/components/app-navigation.blade.php`

**Cel**: Dać użytkownikowi prywatną przestrzeń i jasny model mentalny przed implementacją playlist.

**Kontrakt**: Widok identyfikuje aktywne konto, informuje, że przyszłe playlisty zobaczy tylko właściciel, udostępnia wylogowanie i nie zawiera aktywnego ani nieaktywnego formularza importu. Nie wykonuje zapytań o playlisty i nie tworzy modelu domenowego.

#### 4. Testy dostępu i treści

**Pliki**: `tests/Feature/LandingPageTest.php`, `tests/Feature/BankAccessTest.php`, `tests/Feature/ExampleTest.php`

**Cel**: Utrwalić publiczność landingu, middleware banku i brak przypadkowego wycieku pomiędzy stanami sesji.

**Kontrakt**: Testy potwierdzają CTA dla gościa/użytkownika, redirect gościa do loginu, redirect unverified user do weryfikacji, dostęp verified user, brak danych innego użytkownika w renderowanym widoku i skuteczne wylogowanie. Startowy `ExampleTest` zostaje zastąpiony testami nazwanych zachowań albo usunięty, jeśli jest w pełni redundantny.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Testy landingu i banku przechodzą: `php artisan test tests/Feature/LandingPageTest.php tests/Feature/BankAccessTest.php`.
- `php artisan route:list --except-vendor` pokazuje publiczne `home`, chronione `bank.index` i dwie nazwane trasy Google bez `/dashboard`.
- Gość nie otrzymuje treści banku, użytkownik bez weryfikacji nie omija weryfikacji, a verified user widzi wyłącznie swój kontekst sesji.
- Produkcyjny frontend buduje się: `npm run build`.
- PHP ma poprawny styl: `vendor/bin/pint --test`.

#### Weryfikacja ręczna

- Landing i bank są czytelne oraz używalne klawiaturą na telefonie i desktopie, przy powiększeniu 200% i w jasnym/ciemnym schemacie, jeśli zachowany zostanie przełącznik motywu.
- Pusty stan jasno mówi o prywatności i przyszłym imporcie, ale nie zawiera martwej kontroli.
- Nawigacja gość → auth → weryfikacja → bank → wylogowanie nie prowadzi do ślepych ekranów ani pętli redirectów.

**Uwaga implementacyjna**: Po zakończeniu fazy zatrzymaj się na ręczną akceptację całego UX S-01 przed zmianą CI i przygotowaniem wydania.

---

## Faza 5: Utwardzenie i gotowość wydania

### Przegląd

Faza uruchamia pełną regresję, dokłada izolowany PostgreSQL smoke do CI i przygotowuje bezpieczne, jawne kroki wydania migracji przez `s-manager`.

### Wymagane zmiany

#### 1. Izolowany PostgreSQL w CI

**Plik**: `.github/workflows/ci.yml`

**Cel**: Sprawdzić migracje oraz krytyczne zachowania auth na tym samym silniku bazy co produkcja, bez używania produkcyjnego `music_map`.

**Kontrakt**: Osobny zależny job uruchamia jednorazowy service container PostgreSQL z niesekretną nazwą bazy i poświadczeniami, healthcheckiem oraz bazą izolowaną dla runu. `setup-php` instaluje `pdo_pgsql`; job jawnie nadpisuje `DB_CONNECTION=pgsql`, wszystkie wymagane `DB_*`, `SESSION_DRIVER=array`, `CACHE_STORE=array` i `QUEUE_CONNECTION=sync`, aby nie odziedziczyć SQLite z `phpunit.xml`. Najpierw wykonuje `migrate:fresh`, następnie krytyczne testy auth/identity; obecna szybka macierz SQLite pozostaje pełną bramką regresji.

#### 2. Kontrakt źródła i dokumentacja środowiska

**Pliki**: `scripts/verify-source-contract`, `README.md`

**Cel**: Dodać nowe krytyczne pliki auth do sprawdzanego kontraktu i opisać bezpieczną lokalną konfigurację Google, poczty oraz testowego PostgreSQL.

**Kontrakt**: Source contract wymaga providerów, konfiguracji, modeli, migracji i głównych testów bez dopuszczania sekretów. README używa placeholderów i nie instruuje ręcznego tworzenia tabel.

#### 3. Pełna regresja i zgodność wydania

**Pliki**: `composer.json`, `.github/workflows/ci.yml`

**Cel**: Zachować dotychczasowe bramki jakości i upewnić się, że dodane pakiety oraz migracje nie naruszają kontraktów produkcyjnych obrazów.

**Kontrakt**: Kanoniczne komendy pozostają `composer test`, `vendor/bin/pint --test` i `npm run build`; CI nadal wykonuje audyty, source security i budowę czterech obrazów. Zmiana nie automatyzuje produkcyjnego `schema-release`.

#### 4. Nadzorowane wydanie schematu

**Pliki**: `context/changes/private-account-and-bank/plan.md` (kryteria ręczne), bez zmian w `/srv/manager`

**Cel**: Utrwalić właściwą kolejność operacyjną dla pierwszej funkcji zmieniającej baseline.

**Kontrakt**: Przed wydaniem operator naprawia instalację `/usr/local/libexec/s-manager/music-map-provision.py` do zweryfikowanego `root:root` i trybu `0444`, uzyskuje pozytywny `s-manager provision music-map --status`, wykonuje świeży `s-manager backup shared-postgres`, pozytywny `s-manager restore-test shared-postgres`, a następnie `s-manager schema-release music-map` dla dokładnego manifestu i bieżącego release ID. Dopiero potem wdraża ten sam kandydat i sprawdza status oraz ograniczone logi.

### Kryteria sukcesu

#### Weryfikacja automatyczna

- Pełny zestaw PHPUnit przechodzi na SQLite: `composer test`.
- Krytyczne testy auth/identity oraz `migrate:fresh` przechodzą na jednorazowym PostgreSQL w CI.
- PHP formatting przechodzi: `vendor/bin/pint --test`.
- Produkcyjny bundle przechodzi: `npm run build`.
- Kontrakt źródła przechodzi dla worktree i zestawu tracked: `sh scripts/verify-source-contract --worktree` oraz CI `--tracked`.
- Audyty zależności nie zgłaszają podatności blokujących: `composer audit --locked --no-interaction` i `npm audit --audit-level=high`.

#### Weryfikacja ręczna

- Operator potwierdza, że helper provisioningu jest zweryfikowany i root-owned, a `s-manager provision music-map --status` kończy się sukcesem.
- Świeży backup i isolated restore PostgreSQL kończą się sukcesem przed zmianą produkcyjnego schematu.
- `s-manager schema-release` stosuje wyłącznie dwie oczekujące migracje z dokładnego obrazu, a ledger/fingerprint po operacji są zgodne.
- Ten sam kandydat wdraża się zdrowo; landing, e-mail auth, Google auth i `/bank` przechodzą smoke test pod `https://music.adamis.me` bez sekretów w logach.

**Uwaga implementacyjna**: Produkcyjnych kroków ręcznych nie wykonuje autonomiczny agent implementujący. Faza kończy się dopiero po jawnej akceptacji operatora i zaznaczeniu ręcznych pozycji w sekcji Postęp.

---

## Strategia testowania

### Testy jednostkowe

- Reguły `ResolveGoogleIdentity`: nowe konto, istniejący e-mail, istniejący subject, zmiana e-maila providera, konflikt subject i równoległe naruszenie unikalności.
- Normalizacja adresów i walidacja haseł, jeśli zostaną wydzielone jako niezależne klasy wartości lub reguły.
- Brak przechowywania tokenów i brak nadpisania nazwy istniejącego użytkownika.

### Testy integracyjne

- Rejestracja → weryfikacja → bank oraz logowanie → remember/no-remember → wylogowanie.
- Żądanie resetu → wiadomość → poprawny, błędny i wygasły token → nowe hasło.
- Google redirect/callback z Socialite fake: nowe konto, linkowanie tego samego e-maila, idempotencja, konflikt i odmowa.
- Middleware `guest`, `auth` i `verified` dla landingu, auth screens i `/bank`.
- Migracje i krytyczna macierz auth na SQLite oraz PostgreSQL.

### Kroki testowania ręcznego

1. Założyć konto e-mailowe w testowej skrzynce, sprawdzić blokadę banku przed weryfikacją, potwierdzić e-mail i wejść do `/bank`.
2. Wylogować się, sprawdzić sesję bez „Zapamiętaj mnie”, następnie powtórzyć ze świadomie zaznaczoną opcją.
3. Uruchomić reset hasła, upewnić się, że wiadomość i link działają, a poprzednie hasło przestaje działać.
4. Na koncie o tym samym e-mailu zalogować się przez prawdziwego testowego klienta Google i potwierdzić, że nie powstał drugi użytkownik.
5. Anulować zgodę Google i sprawdzić bezpieczny powrót do logowania bez częściowych danych.
6. Sprawdzić landing i bank klawiaturą, na wąskim ekranie oraz przy powiększeniu 200%.
7. Po nadzorowanym wydaniu schematu wykonać smoke test HTTPS i przejrzeć ograniczone logi pod kątem wycieku sekretów.

## Uwagi dotyczące wydajności

S-01 ma mały wolumen i nie wymaga cache ani kolejek. Zapytania logowania korzystają z istniejącego indeksu unikalnego `users.email`, a lookup Google z nowego unikalnego indeksu `(provider, provider_user_id)`. Callback wykonuje stałą liczbę zapytań w jednej krótkiej transakcji; nie wolno trzymać transakcji otwartej podczas połączenia sieciowego z Google.

Rate limiting powinien ograniczać koszt hashowania haseł i nadużycia endpointów logowania, ponowienia weryfikacji oraz resetu. Test PostgreSQL w CI obejmuje wyłącznie krytyczną macierz, aby nie dublować całego czasu szybkiego zestawu SQLite.

## Uwagi dotyczące migracji

Aktywny produkcyjny baseline już zawiera `users`, `password_reset_tokens`, `sessions`, cache i jobs. Plan dodaje dwie migracje: rozluźnienie `users.password` do nullable oraz nową tabelę `auth_identities`. Obie muszą być kompatybilne wstecz ze starym obrazem; stary kod nadal obsłuży istniejących użytkowników z hasłem i zignoruje nową tabelę.

Nie wykonywać ręcznego SQL. Przed produkcyjną zmianą należy naprawić właściciela helpera provisioningu, zweryfikować provisioning, wykonać świeży backup i isolated restore, a następnie zastosować migracje przez `s-manager schema-release` z dokładnego manifestu. Automatyczny reconciler nie uruchamia migracji i nie wykonuje `down` podczas rollbacku obrazu.

## Referencje

- Zakres produktu: `context/foundation/prd.md:69`, `context/foundation/prd.md:129`.
- Wycinek S-01: `context/foundation/roadmap.md:94`.
- Decyzja Livewire/Flux: `context/foundation/tech-stack.md:22`.
- Obecny model użytkownika: `app/Models/User.php:13`.
- Obecny schemat użytkownika i sesji: `database/migrations/0001_01_01_000000_create_users_table.php:14`.
- Obecny routing: `routes/web.php:3`.
- Kontrakt wydania schematu: `context/foundation/infrastructure.md:70`, `/srv/manager/docs/music-map-provision.md:74`.
- Oficjalny Laravel Livewire Starter Kit: `https://github.com/laravel/livewire-starter-kit`.
- Laravel 13 Starter Kits / Fortify: `https://github.com/laravel/docs/blob/13.x/starter-kits.md`.
- Laravel Socialite: `https://laravel.com/framework/docs/socialite`.

## Postęp

> Konwencja: `- [ ]` oczekujące, `- [x]` wykonane. Dodaj ` — <commit sha>` po wylądowaniu kroku. Nie zmieniaj nazw tytułów kroków.

### Faza 1: Fundament auth i model tożsamości

#### Automatyczne

- [x] 1.1 Zainstalować zależności z lockfile i uruchomić aplikację bez błędów providerów — 081c42c
- [x] 1.2 Zastosować i cofnąć migracje na jednorazowej bazie SQLite — 081c42c
- [x] 1.3 Potwierdzić kontrakt modelu auth identities testami — 081c42c
- [x] 1.4 Sprawdzić formatowanie PHP — 081c42c

#### Ręczne

- [x] 1.5 Potwierdzić zgodność migracji expand/contract z bieżącym obrazem — 081c42c
- [x] 1.6 Potwierdzić brak sekretów i tokenów Google w repozytorium, logach i tabelach — 081c42c

### Faza 2: Kompletny przepływ e-mailowy

#### Automatyczne

- [x] 2.1 Uruchomić pełną macierz e-mailowego auth — 12a4b61
- [x] 2.2 Potwierdzić jedno konto dla wariantów znormalizowanego e-maila — 12a4b61
- [x] 2.3 Potwierdzić throttling i bezpieczeństwo cyklu sesji — 12a4b61
- [x] 2.4 Zbudować widoki w produkcyjnym bundle — 12a4b61
- [x] 2.5 Sprawdzić formatowanie PHP — 12a4b61

#### Ręczne

- [x] 2.6 Przejść rejestrację, weryfikację, logowanie, wylogowanie i reset z testową pocztą — 12a4b61
- [x] 2.7 Zweryfikować klawiaturę, fokus, błędy oraz układ mobilny i desktopowy — 12a4b61
- [x] 2.8 Potwierdzić zachowanie sesji bez opcji Zapamiętaj mnie — 12a4b61

### Faza 3: Logowanie i łączenie przez Google

#### Automatyczne

- [x] 3.1 Uruchomić testy serwisu i callbacku Google bez sieci — 05f617c
- [x] 3.2 Potwierdzić idempotencję powtórzonego callbacku Google — 05f617c
- [x] 3.3 Potwierdzić scalenie zweryfikowanego e-maila do jednego konta — 05f617c
- [x] 3.4 Potwierdzić atomową odmowę dla wszystkich konfliktów identity — 05f617c
- [x] 3.5 Sprawdzić formatowanie PHP — 05f617c

#### Ręczne

- [x] 3.6 Przejść redirect i callback prawdziwego testowego klienta Google — 05f617c
- [x] 3.7 Zweryfikować bezpieczne anulowanie i obsługę błędu Google — 05f617c
- [x] 3.8 Potwierdzić brak poświadczeń i pełnych danych Google w logach — 05f617c

### Faza 4: Publiczne wejście i prywatny bank

#### Automatyczne

- [x] 4.1 Uruchomić testy landingu i dostępu do banku — cccedad
- [x] 4.2 Potwierdzić kontrakt nazwanych tras i brak dashboardu — cccedad
- [x] 4.3 Potwierdzić egzekwowanie auth i verified dla banku — cccedad
- [x] 4.4 Zbudować produkcyjny frontend — cccedad
- [x] 4.5 Sprawdzić formatowanie PHP — cccedad

#### Ręczne

- [x] 4.6 Zweryfikować responsywność i dostępność landingu oraz banku — cccedad
- [x] 4.7 Potwierdzić informacyjny pusty stan bez martwych kontroli — cccedad
- [x] 4.8 Przejść pełną nawigację od gościa do wylogowania bez ślepych ekranów — cccedad

### Faza 5: Utwardzenie i gotowość wydania

#### Automatyczne

- [x] 5.1 Uruchomić pełny zestaw PHPUnit na SQLite
- [x] 5.2 Uruchomić migracje i krytyczną macierz auth na jednorazowym PostgreSQL
- [x] 5.3 Sprawdzić formatowanie PHP
- [x] 5.4 Zbudować produkcyjny bundle frontendowy
- [x] 5.5 Zweryfikować kontrakt źródła w worktree i CI
- [x] 5.6 Uruchomić audyty zależności PHP i Node

#### Ręczne

- [x] 5.7 Naprawić i zweryfikować root-owned helper provisioningu Music Map
- [ ] 5.8 Wykonać świeży backup i isolated restore PostgreSQL
- [ ] 5.9 Zastosować dwie migracje przez nadzorowany schema-release i potwierdzić ledger
- [ ] 5.10 Wdrożyć dokładnego kandydata i zaliczyć produkcyjny smoke test bez wycieku sekretów
