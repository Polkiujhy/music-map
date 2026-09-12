---
project: music-map
version: 1
status: draft
created: 2026-09-11
updated: 2026-09-12
prd_version: 1
main_goal: speed
top_blocker: external
milestone_id: independent-playlist-bank
milestone_seq: 1
milestone_status: open
---

# Mapa drogowa: music-map

> Pochodzi z `context/foundation/prd.md` (v1) oraz automatycznie zbadanej bazy kodu.
> Edytuj na miejscu; archiwizuj, gdy zostanie zastąpiona.
> Wycinki poniżej są wymienione w kolejności zależności. Tabela „W skrócie” to indeks.

## Kamień milowy

**M-1: Niezależny bank i bezpieczne przenoszenie playlist** — Status: open

- **Cel:** użytkownik zachowuje playlistę w prywatnym banku niezależnym od platformy, a następnie może bezpiecznie przenieść ją między Spotify i YouTube oraz utrzymywać powiązane kopie w zgodności.
- **Materiały źródłowe:** `context/foundation/prd.md` (v1).
- **Gotowe, gdy:** każdy F-NN i S-NN poniżej jest `done`, a przepływ dla playlisty do 50 utworów zachowuje świadome potwierdzenie, informację o właścicielu wyniku i możliwość bezpiecznego ponowienia operacji.
- **Kotwice zakresu:** FR-001–FR-002, FR-004–FR-011, FR-014–FR-015, US-01–US-02, NFR-001–NFR-005.

## Podsumowanie wizji

`music-map` ma być trwałym, prywatnym bankiem playlist niezależnym od aktualnej subskrypcji użytkownika. Rozwiązuje problem kolekcji uwięzionych na platformie: użytkownik importuje playlistę, kontroluje dopasowanie utworów i przenosi ją na drugą platformę bez ręcznego odtwarzania zawartości.

## Gwiazda północna

**S-02: Import playlisty z linku do prywatnego banku** — jest to najmniejszy pełny przepływ, który potwierdza podstawową wartość produktu przy celu `speed`: playlista staje się trwała i dostępna niezależnie od platformy.

> „Gwiazda północna” oznacza tutaj najmniejszy kompleksowy wycinek, którego dostarczenie potwierdza, że podstawowy pomysł produktu działa; dlatego trafia najwcześniej, jak pozwalają wymagania wstępne.

## W skrócie

| ID | Change ID | Wynik (użytkownik może …) | Wymagania wstępne | Odniesienia do PRD | Status |
| ----- | ---------------------- | --------------------------------- | ---------------- | -------------- | -------- |
| F-01 | `platform-access-readiness` | (fundament) zweryfikowano minimalny dostęp aplikacji i kont technicznych do Spotify i YouTube oraz bezpieczną obsługę poświadczeń | aktywne projekty deweloperskie, poświadczenia aplikacji i konta techniczne Spotify oraz YouTube | FR-004, FR-006, FR-009, FR-010, NFR-003 | planning |
| S-01 | `private-account-and-bank` | utworzyć konto, zalogować się i wejść do własnego pustego banku playlist | — | FR-001, FR-002 | done |
| S-02 | `playlist-link-import` | zaimportować playlistę z linku do prywatnego banku albo zobaczyć przyczynę odmowy | F-01, S-01 | FR-002, FR-004, NFR-001, NFR-005 | proposed |
| S-03 | `bank-playlist-editing` | przeglądać i edytować zawartość playlisty zapisanej w banku | S-02 | FR-002, FR-005 | proposed |
| S-04 | `streaming-account-linking` | powiązać lub odłączyć konto Spotify albo YouTube bez pozostawienia aktywnej synchronizacji | F-01, S-01 | FR-006, NFR-003 | proposed |
| S-05 | `export-match-review` | wybrać dozwolony cel, sprawdzić dopasowania i świadomie zatwierdzić eksport | F-01, S-02 | US-01, FR-007, FR-008, NFR-001, NFR-002 | proposed |
| S-06 | `managed-account-export` | przenieść playlistę na konto techniczne `music-map`, poznać jej właściciela i bezpiecznie ponowić niepełny eksport | S-05 | US-01, FR-010, FR-011 | proposed |
| S-07 | `linked-account-export` | utworzyć albo zaktualizować playlistę na powiązanym koncie i zobaczyć jednoznaczny wynik | S-04, S-05 | US-01, FR-009, FR-011 | proposed |
| S-08 | `source-playlist-sync` | ręcznie lub automatycznie synchronizować własne źródło z bankiem przy jasnej regule konfliktu | S-03, S-04 | US-02, FR-005, NFR-004 | proposed |
| S-09 | `playlist-drift-recovery` | zobaczyć rozbieżność powiązanych playlist i przywrócić zgodność bez tworzenia duplikatu | S-07, S-08 | US-02, FR-002, FR-014 | proposed |
| S-10 | `safe-account-deletion` | usunąć konto po poznaniu skutków, zachowując playlisty należące do niego na platformach | S-04, S-06, S-07 | FR-015, NFR-003 | proposed |

## Strumienie

Pomoc nawigacyjna — grupuje elementy, które współdzielą łańcuch wymagań wstępnych. Kanoniczna kolejność nadal znajduje się w grafie zależności poniżej; ta tabela to proponowana kolejność czytania w równoległych ścieżkach.

| Strumień | Temat | Łańcuch | Uwaga |
| ------ | ------------------ | ------------------------------ | --------------------------------------------------------- |
| A | Prywatny bank i synchronizacja | `S-01` → `S-02` → `S-03` → `S-08` → `S-09` | Prowadzi najszybciej do S-02; w S-09 łączy się ze Strumieniem B. |
| B | Dostęp do platform i własność eksportu | `F-01` → `S-04` → `S-07` → `S-10` | Najpierw usuwa główne ryzyko zewnętrzne; w S-10 łączy się ze Strumieniem C. |
| C | Kontrola i eksport zarządzany | `S-05` → `S-06` | W S-05 łączy bank ze Strumienia A z dostępem ze Strumienia B i prowadzi do wybranego eksportu na konto techniczne. |

## Baza

Co już jest na miejscu w bazie kodu na dzień `2026-09-11` (automatycznie zbadane i potwierdzone przez użytkownika). Fundamenty poniżej zakładają ten stan i nie odbudowują elementów oznaczonych jako obecne.

- **Frontend:** częściowy — Blade, Tailwind CSS 4 i Vite są skonfigurowane (`resources/views/welcome.blade.php`, `vite.config.js`), ale nie ma własnych komponentów, a kod JavaScript jest pusty.
- **Backend / API:** częściowy — Laravel 13 i routing webowy działają (`composer.json`, `bootstrap/app.php`), ale istnieje tylko startowa trasa bez kontrolerów aplikacyjnych i API.
- **Dane:** obecny — PostgreSQL, Eloquent, migracje i seeder są podłączone (`.env.example`, `app/Models/User.php`, `database/migrations/`).
- **Autoryzacja:** częściowy — istnieją sesyjny mechanizm autoryzacji, model użytkownika i tabele sesji/resetów (`config/auth.php`, migracja użytkowników), ale nie ma przepływów logowania, Google OAuth ani ochrony tras.
- **Wdrożenie / infrastruktura:** częściowy — są obrazy produkcyjne, kontrole zdrowia i proces budowania obrazów (`Dockerfile`, `.github/workflows/ci.yml`), ale repozytorium nie zawiera konfiguracji docelowego wdrożenia ani infrastruktury jako kodu.
- **Obserwowalność:** częściowy — logowanie do `stderr` i kontrole zdrowia istnieją (`config/logging.php`, `docker/nginx/default.conf`), ale brakuje śledzenia błędów, metryk i pulpitów.

## Fundamenty

### F-01: Gotowość dostępu do platform

- **Wynik:** (fundament) zweryfikowano minimalny dostęp aplikacji i kont technicznych do Spotify i YouTube, a poświadczenia mają bezpieczny cykl przechowywania i unieważniania.
- **Change ID:** `platform-access-readiness`
- **Odniesienia do PRD:** FR-004, FR-006, FR-009, FR-010, NFR-003.
- **Odblokowuje:** S-02, S-04, S-05, S-06 i S-07 oraz weryfikację importu i eksportu na obu platformach.
- **Wymagania wstępne:** aktywne projekty deweloperskie, poświadczenia aplikacji i konta techniczne Spotify oraz YouTube.
- **Równolegle z:** S-01.
- **Blokery:** zatwierdzenie dostępu i ograniczenia narzucone przez Spotify oraz YouTube.
- **Niewiadome:** czy projekty deweloperskie, wymagane poświadczenia i konta techniczne obu platform są już aktywne? — Właściciel: użytkownik. Blok: tak.
- **Ryzyko:** brak choć jednego dostępu ujawniłby się dopiero podczas budowy importu lub eksportu i zatrzymał najkrótszą ścieżkę do działającego produktu.
- **Status:** planning

## Wycinki

### S-01: Prywatne konto i wejście do banku

- **Wynik:** użytkownik może utworzyć konto, zalogować się e-mailem lub przez Google i wejść do własnego pustego banku playlist bez dostępu do danych innych osób.
- **Change ID:** `private-account-and-bank`
- **Odniesienia do PRD:** FR-001, FR-002.
- **Wymagania wstępne:** —
- **Równolegle z:** F-01.
- **Blokery:** —
- **Niewiadome:** —
- **Ryzyko:** połączenie metod logowania po adresie e-mail musi zapobiegać powieleniu kont, bo wszystkie kolejne wycinki opierają własność danych na jednej tożsamości.
- **Status:** done

### S-02: Import playlisty z linku do banku

- **Wynik:** zalogowany użytkownik może zaimportować dostępną playlistę z linku Spotify lub YouTube do prywatnego banku, a przy odmowie zobaczyć przyczynę i możliwe rozwiązanie.
- **Change ID:** `playlist-link-import`
- **Odniesienia do PRD:** FR-002, FR-004, NFR-001, NFR-005.
- **Wymagania wstępne:** F-01, S-01.
- **Równolegle z:** —
- **Blokery:** dostęp odczytowy udostępniony aplikacji przez Spotify i YouTube.
- **Niewiadome:** —
- **Ryzyko:** link udostępniania nie gwarantuje dostępu do zawartości, więc odmowa platformy musi być pełnoprawnym, czytelnym wynikiem zamiast błędu technicznego.
- **Status:** proposed

### S-03: Edycja playlisty w banku

- **Wynik:** użytkownik może przeglądać i edytować utwory playlisty zapisanej w niezależnym banku, zachowując jej pochodzenie.
- **Change ID:** `bank-playlist-editing`
- **Odniesienia do PRD:** FR-002, FR-005.
- **Wymagania wstępne:** S-02.
- **Równolegle z:** S-04.
- **Blokery:** —
- **Niewiadome:** —
- **Ryzyko:** edycja nie może zatrzeć nadrzędnego źródła ani identyfikatora platformy, bo późniejsza synchronizacja używa ich do aktualizacji właściwej playlisty.
- **Status:** proposed

### S-04: Powiązanie kont streamingowych

- **Wynik:** użytkownik może bezpiecznie powiązać lub odłączyć konto Spotify albo YouTube, a odłączenie wyłącza synchronizację wszystkich zależnych playlist.
- **Change ID:** `streaming-account-linking`
- **Odniesienia do PRD:** FR-006, NFR-003.
- **Wymagania wstępne:** F-01, S-01.
- **Równolegle z:** S-03.
- **Blokery:** zgody i zakresy dostępu udostępnione przez Spotify oraz YouTube.
- **Niewiadome:** —
- **Ryzyko:** zbyt szerokie zakresy lub pozostawienie aktywnych tokenów po odłączeniu narusza wymóg poufności i blokuje bezpieczny eksport na konto użytkownika.
- **Status:** proposed

### S-05: Kontrola dopasowania przed eksportem

- **Wynik:** użytkownik może wybrać dozwoloną platformę docelową, zobaczyć dopasowane, błędnie dopasowane i niedostępne utwory, zdecydować o ich pozostawieniu lub usunięciu i świadomie potwierdzić eksport.
- **Change ID:** `export-match-review`
- **Odniesienia do PRD:** US-01, FR-007, FR-008, NFR-001, NFR-002.
- **Wymagania wstępne:** F-01, S-02.
- **Równolegle z:** S-03, S-04.
- **Blokery:** dostęp do katalogów utworów Spotify i YouTube.
- **Niewiadome:** —
- **Ryzyko:** błędne rozróżnienie utworu brakującego od źle dopasowanego mogłoby skłonić użytkownika do zatwierdzenia innej zawartości niż pokazana.
- **Status:** proposed

### S-06: Eksport na konto techniczne music-map

- **Wynik:** użytkownik bez powiązanego konta może utworzyć lub zaktualizować playlistę na koncie technicznym `music-map`, otrzymać stały link i informację o właścicielu oraz bezpiecznie ponowić przerwaną operację.
- **Change ID:** `managed-account-export`
- **Odniesienia do PRD:** US-01, FR-010, FR-011.
- **Wymagania wstępne:** S-05.
- **Równolegle z:** S-07.
- **Blokery:** prawo kont technicznych do tworzenia i aktualizowania playlist oraz ograniczenia widoczności narzucone przez platformy.
- **Niewiadome:** —
- **Ryzyko:** ponowienie po częściowym sukcesie musi aktualizować playlistę po zapisanym ID, inaczej awaria utworzy duplikaty i złamie główną obietnicę produktu.
- **Status:** proposed

### S-07: Eksport na powiązane konto użytkownika

- **Wynik:** użytkownik może utworzyć lub zaktualizować playlistę na własnym powiązanym koncie, z właściwą widocznością oraz jednoznacznym statusem i linkiem.
- **Change ID:** `linked-account-export`
- **Odniesienia do PRD:** US-01, FR-009, FR-011.
- **Wymagania wstępne:** S-04, S-05.
- **Równolegle z:** S-06.
- **Blokery:** zakresy zapisu przyznane aplikacji przez użytkownika i ograniczenia widoczności playlist w API platform.
- **Niewiadome:** —
- **Ryzyko:** rozpoznawanie celu inaczej niż po zapisanym ID grozi utworzeniem kolejnych kopii lub zmianą niewłaściwej playlisty.
- **Status:** proposed

### S-08: Synchronizacja playlisty źródłowej

- **Wynik:** użytkownik może ręcznie albo automatycznie synchronizować playlistę należącą do jego powiązanego konta, a w konflikcie bank przyjmuje wersję platformy źródłowej.
- **Change ID:** `source-playlist-sync`
- **Odniesienia do PRD:** US-02, FR-005, NFR-004.
- **Wymagania wstępne:** S-03, S-04.
- **Równolegle z:** S-05.
- **Blokery:** limity i dostępność cyklicznych odczytów oraz zapisów w API Spotify i YouTube.
- **Niewiadome:** —
- **Ryzyko:** niejednoznaczne wykrycie zmian od ostatniej synchronizacji może nadpisać edycję użytkownika lub uruchomić zbędne aktualizacje.
- **Status:** proposed

### S-09: Wykrywanie i naprawa rozbieżności

- **Wynik:** użytkownik może zobaczyć, która powiązana playlista jest nieaktualna, przejrzeć różnice i ponownie wyeksportować źródło do istniejącej playlisty bez tworzenia duplikatu.
- **Change ID:** `playlist-drift-recovery`
- **Odniesienia do PRD:** US-02, FR-002, FR-014.
- **Wymagania wstępne:** S-07, S-08.
- **Równolegle z:** S-10.
- **Blokery:** —
- **Niewiadome:** —
- **Ryzyko:** status aktualności musi wynikać z porównania właściwej relacji źródło–eksport, inaczej użytkownik otrzyma mylące powiadomienie lub naprawi niewłaściwą kopię.
- **Status:** proposed

### S-10: Bezpieczne usunięcie konta

- **Wynik:** użytkownik może zobaczyć skutki, potwierdzić usunięcie konta i usunąć dane aplikacji oraz zarządzane kopie bez kasowania playlist należących do niego na Spotify lub YouTube.
- **Change ID:** `safe-account-deletion`
- **Odniesienia do PRD:** FR-015, NFR-003.
- **Wymagania wstępne:** S-04, S-06, S-07.
- **Równolegle z:** S-09.
- **Blokery:** możliwość usuwania playlist z kont technicznych w granicach API platform.
- **Niewiadome:** —
- **Ryzyko:** pomylenie własności playlisty może usunąć cudzy zasób albo pozostawić dane i aktywne poświadczenia po zamknięciu konta.
- **Status:** proposed

## Przekazanie do backlogu

| ID mapy drogowej | Change ID | Sugerowany tytuł zadania | Gotowe do `/10x-plan` | Uwagi |
| ---------- | ---------------------- | ----------------------------- | --------------------- | ----- |
| F-01 | `platform-access-readiness` | Zweryfikuj dostęp aplikacji i kont technicznych do platform | no | Najpierw potwierdź dostęp i poświadczenia obu platform. |
| S-01 | `private-account-and-bank` | Udostępnij prywatne konto i pusty bank playlist | yes | Uruchom `/10x-plan private-account-and-bank`. |
| S-02 | `playlist-link-import` | Importuj playlistę z linku do prywatnego banku | no | Czeka na F-01 i S-01. |
| S-03 | `bank-playlist-editing` | Pozwól edytować playlistę w banku | no | Czeka na S-02. |
| S-04 | `streaming-account-linking` | Powiąż i odłącz konta streamingowe | no | Czeka na F-01 i S-01. |
| S-05 | `export-match-review` | Pokaż dopasowania i potwierdzenie eksportu | no | Czeka na F-01 i S-02. |
| S-06 | `managed-account-export` | Eksportuj na konto techniczne music-map | no | Czeka na S-05. |
| S-07 | `linked-account-export` | Eksportuj na powiązane konto użytkownika | no | Czeka na S-04 i S-05. |
| S-08 | `source-playlist-sync` | Synchronizuj playlistę źródłową z bankiem | no | Czeka na S-03 i S-04. |
| S-09 | `playlist-drift-recovery` | Wykrywaj i naprawiaj rozbieżności playlist | no | Czeka na S-07 i S-08. |
| S-10 | `safe-account-deletion` | Usuń konto zgodnie z własnością zasobów | no | Czeka na S-04, S-06 i S-07. |

## Otwarte pytania dotyczące mapy drogowej

1. **Czy projekty deweloperskie, wymagane poświadczenia i konta techniczne Spotify oraz YouTube są już aktywne?** — Właściciel: użytkownik. Blok: F-01, S-02, S-04, S-05, S-06, S-07.

## Zaparkowane

- **Ręczne tworzenie playlist od zera** — Dlaczego zaparkowane: PRD §Non-Goals oraz FR-003 oznaczone jako funkcja dodatkowa.
- **Wizualna mapa autorów i utworów** — Dlaczego zaparkowane: PRD §Non-Goals oraz FR-012 poza zakresem MVP.
- **Ręczny wybór zamienników z zewnętrznego katalogu** — Dlaczego zaparkowane: PRD §Non-Goals oraz FR-013 poza zakresem MVP.
- **Obsługa kolejnych platform muzycznych** — Dlaczego zaparkowane: PRD §Non-Goals ogranicza MVP do Spotify i YouTube.
- **Gwarantowana obsługa playlist powyżej 50 utworów** — Dlaczego zaparkowane: PRD §Non-Goals oraz limit NFR-001.
- **Udostępnianie banku i rozbudowane role** — Dlaczego zaparkowane: PRD §Non-Goals utrzymuje prywatny bank i płaski model uprawnień.
- **Wzbogacanie danych o daty premier** — Dlaczego zaparkowane: PRD §Non-Goals pozostawia dane zewnętrzne tylko do odczytu.
- **Paywall, subskrypcje i rozliczenia** — Dlaczego zaparkowane: PRD §Non-Goals wyłącza monetyzację z MVP.

## Historia kamieni milowych


## Zrobione

- **S-01: użytkownik może utworzyć konto, zalogować się e-mailem lub przez Google i wejść do własnego pustego banku playlist bez dostępu do danych innych osób.** — Zarchiwizowano 2026-09-12 → `context/archive/2026-09-12-private-account-and-bank/`. Lekcja: —.
