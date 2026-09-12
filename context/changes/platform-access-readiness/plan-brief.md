# Gotowość dostępu do Spotify i YouTube — krótki plan

> **Plan zastąpiony:** aktualny plan pełnej gotowości znajduje się w
> `/srv/manager/context/changes/music-map-platform-access-readiness/plan.md`.

> Pełny plan: `context/changes/platform-access-readiness/plan.md`

## Co i dlaczego

Budujemy fundament, który lokalnie dowodzi minimalnego dostępu testowych aplikacji i dedykowanych kont technicznych do Spotify oraz YouTube, zanim kolejne wycinki oprą się na tych API. Zmiana definiuje też bezpieczny kontrakt konfiguracji produkcyjnej, ale nie testuje live sekretów zarządzanych przez Manager.

## Punkt wyjścia

Repo ma bezpieczny Google OAuth do logowania, lecz nie zawiera żadnego kodu, konfiguracji ani testów Spotify/YouTube. Aktualne API ujawniły dwa ograniczenia: Spotify nie udostępnia utworów dowolnej publicznej playlisty bez OAuth właściciela/współpracownika, a zapis 20 elementów do YouTube zużywa znaczną część domyślnej dziennej kwoty.

## Pożądany stan końcowy

Lokalna komenda pokazuje per platforma wyniki `config/auth/read/write/cleanup` i wykonuje zapis tylko po jawnej fladze. Spotify przywraca stan dedykowanej prywatnej playlisty testowej, a YouTube usuwa jednorazowy zasób. Oddzielny lokalny bootstrap OAuth uzyskuje i zapisuje poświadczenia bez ich wyświetlania. Automatyczne testy działają bez sieci, a redagowany raport dokumentuje rzeczywiste próby na osobnych kontach testowych bez sekretów, PII i identyfikatorów zasobów.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Import Spotify | Tylko właściciel lub współpracownik po OAuth | Oficjalne API odmawia odczytu elementów pozostałych playlist | Plan |
| Środowiska | Osobne test i produkcja | Lokalne próby nie mogą mutować produkcji | Plan |
| Konta techniczne | Dedykowane konta projektu | Spotify Client Credentials i YouTube service accounts nie zapisują playlist użytkownika | Badania |
| Spotify scopes | Read private/collaborative + modify private | Pokrywa MVP bez publicznego zapisu | Plan |
| YouTube dostęp | API key do publicznego read, `youtube.force-ssl` do write | Ogranicza szeroką zgodę do operacji, które jej wymagają | Badania / Plan |
| Tokeny techniczne | Lokalny `.env`; produkcja przez PaaS | F-01 pozostaje bez migracji i nie przejmuje S-04 | Plan |
| Bootstrap OAuth | Lokalna komenda z callbackiem loopback, state i PKCE | Umożliwia revoke→reauthorize bez tras aplikacji i kopiowania tokenów | Plan |
| Odłączanie | Platform-specific revoke | YouTube ma revoke API, Spotify wymaga ręcznego cofnięcia zgody | Badania / Plan |
| Próby zapisu | Jawne `--write` + cleanup | Mutacja ma być świadoma i odwracalna | Plan |
| Bramka F-01 | Lokalne live testy bez produkcyjnego smoke | Produkcja jest zarządzana osobno przez Manager | Plan |
| Wynik | Macierz zdolności + niezerowy exit code | Wskazuje dokładnie blokowany wycinek | Plan |
| Limit playlisty | 20 utworów | Zmniejsza koszt i ryzyko przed terminem MVP | Plan |
| Budżet YouTube | 5 rozpoczętych zapisów dziennie globalnie | Zachowuje bufor w domyślnej kwocie 10 000 | Plan |
| Dowód | Redagowany `verification.md` | Kolejne zmiany otrzymują trwały, współczesny punkt odniesienia | Plan |

## Zakres

**W zakresie:** kontrakt env/config, minimalne scope, typowany wynik, lokalny bootstrap OAuth, probe Spotify i YouTube, komenda readiness, testy HTTP fake, lokalne read/write/cleanup/revoke oraz redagowany dowód.

**Poza zakresem:** UI, trasy aplikacji i callbacki linkowania kont użytkowników, baza tokenów użytkowników, import/eksport domenowy, produkcyjny live smoke, runtime enforcement kwoty, zmiany Managera i zwiększenie kwoty Google.

## Architektura / Podejście

Komenda `platforms:readiness` składa dwa cienkie probe'y za wspólnym kontraktem wyniku. Spotify używa OAuth konta technicznego do read/write i w `finally` przywraca dedykowany prywatny fixture, natomiast YouTube rozdziela publiczny read przez API key od write przez OAuth oraz usuwa jednorazową playlistę `unlisted`. Oddzielne `platforms:authorize` działa tylko lokalnie, obsługuje callback loopback i bezpiecznie aktualizuje ignorowany `.env`.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Kontrakt platform | Bezpieczne config, scope i schema wyniku | Pomylenie login Google z integracją YouTube |
| 2. Komenda readiness | Powtarzalne probe'y i testy bez sieci | Wyciek danych lub pozostawiony zasób smoke |
| 3. Weryfikacja live | Rzeczywisty test lokalny i redagowany dowód | Testowe poświadczenia lub zewnętrzna kwota nie są gotowe |

**Wymagania wstępne:** osobne projekty testowe, Premium właściciela i allowlista konta Spotify Development Mode, znany status consent screen YouTube, dedykowane konta Spotify i Google/YouTube, wymagane zgody OAuth, fixture do odczytu i zapisu oraz lokalny nieśledzony `.env`.

**Szacowany wysiłek:** około 2 intensywne sesje implementacyjne plus ręczna konfiguracja i autoryzacja providerów przed terminem 14 września 2026, 23:59.

## Otwarte ryzyka i założenia

- F-01 nie dowodzi poprawności produkcyjnych wartości; smoke produkcyjny pozostaje prerequisite wycinków zapisujących.
- Spotify refresh token wygasa po sześciu miesiącach i może rotować; F-01 zapisuje rotację lokalną, a zapisywalny kontrakt produkcyjnego PaaS pozostaje prerequisite przyszłego runtime.
- Refresh token YouTube zewnętrznego projektu w statusie `testing` wygasa po siedmiu dniach i wymaga ponownego bootstrapu.
- Google może wymagać weryfikacji szerokiego scope przed publicznym uruchomieniem YouTube write.
- Limit pięciu zapisów YouTube jest kontraktem produktu, lecz jego egzekwowanie powstanie dopiero razem z eksportem lub synchronizacją.
- Nieudane przywrócenie fixture Spotify albo usunięcie playlisty YouTube blokuje zaliczenie provider readiness i wymaga ręcznej kontroli zasobu testowego.

## Kryteria sukcesu (podsumowanie)

- Obie platformy przechodzą lokalnie macierz auth/read/write/cleanup na dedykowanych kontach testowych.
- Testy automatyczne przechodzą bez sieci i dowodzą stabilnych exit codes oraz braku wycieku danych.
- Redagowany raport utrwala wynik, ograniczenia Spotify/YouTube i brak produkcyjnego smoke bez ujawniania sekretów lub PII.
