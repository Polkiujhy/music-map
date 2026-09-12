# Gotowość dostępu do Spotify i YouTube — krótki plan

> Pełny plan: `context/changes/platform-access-readiness/plan.md`

## Co i dlaczego

Zanim powstaną import, łączenie kont i eksport, `music-map` musi potwierdzić, że aktywne projekty oraz konta Spotify i YouTube rzeczywiście wykonują wymagane operacje. F-01 usuwa fałszywe założenia produktowe, ustanawia bezpieczny kontrakt konfiguracji i zostawia sanitizowany dowód gotowości deweloperskiej.

## Punkt wyjścia

Repozytorium ma bezpieczny wzorzec Google OAuth, placeholdery sekretów i blokujący skan Gitleaks, ale nie ma konfiguracji ani klientów Spotify/YouTube. Aktualne Spotify API nie pozwala nowej aplikacji pobrać elementów dowolnej cudzej publicznej playlisty; YouTube rozdziela publiczny odczyt przez API key od zapisu wymagającego OAuth.

## Pożądany stan końcowy

PRD i roadmapa opisują wykonalne zachowanie obu platform. Runbook potwierdza na dwuelementowych playlistach odczyt, oczekiwane odmowy, tworzenie, aktualizację i widoczność osobno dla kont technicznych i świeżych kont testowych. Każda wymagana zdolność ma sanitizowany `PASS`; dowolne `BLOCKED` zatrzymuje zależne wycinki.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Import Spotify | Tylko playlisty własne/współdzielone po OAuth | API nie udostępnia elementów arbitralnej publicznej playlisty | Badania + Plan |
| Głębokość dowodu | Odczyt, auth, create, update i widoczność | F-01 ma usunąć ryzyko wszystkich zależnych wycinków | Plan |
| Konta techniczne | Dedykowane zwykłe konta Spotify i Google z kanałem | Service accounts nie działają jako zwykły właściciel YouTube | Badania + Plan |
| Konta użytkowników | Osobne, świeże konta testowe | Nie wolno maskować błędu fallbackiem do konta technicznego | Plan |
| Widoczność Spotify | Poza profilem/search, potencjalnie dostępna przez link | `public=false` nie jest ścisłą kontrolą dostępu | Badania + Plan |
| Scope'y | Minimalne i rozdzielone per przepływ | Realizuje zasadę najmniejszych uprawnień | PRD + Plan |
| Klient Google OAuth | Wspólny client ID/secret dla loginu i YouTube, oddzielne granty oraz tokeny | Zgodność z publicznym kontraktem runtime bez mieszania tożsamości | Plan + Manager `music-map.platform-access.v1` |
| Fail-closed | Brak działających wartości domyślnych i odmowa przy użyciu integracji, bez blokowania startu | Providerzy nie mają jeszcze produkcyjnych klientów w F-01 | Plan |
| Mechanizm | Trwały runbook, bez dedykowanej komendy | Unika tymczasowej architektury przed klientami domenowymi | Plan |
| Próba live | Dwa utwory; liczbowy budżet YouTube i budżet żądań Spotify dla 50 | Sprawdza semantykę przy małym koszcie bez wymyślania niepublikowanego limitu Spotify | Plan |
| Dowód | Sanitizowana macierz bez ID, URL-i i PII | Jest audytowalna bez tworzenia wrażliwego artefaktu | Plan |
| Blokada | Każde niepowodzenie zatrzymuje F-01 | Nie budujemy kolejnych funkcji na atrapach | Plan |
| Bramka końcowa | Spotify Development + Google External/Testing | Celem jest gotowość deweloperska, nie publiczny launch | Plan |
| Sekrety techniczne | Cykl życia należy do Managera jako zewnętrznego PaaS | Aplikacja zależy tylko od symbolicznego kontraktu runtime | Lekcje + Plan |

## Zakres

**W zakresie:** korekta PRD/roadmapy; symboliczna konfiguracja Spotify/YouTube; testy fail-closed; dokumentacja sekretów; runbook minimalnych scope'ów i prób; live validation kont technicznych i testowych; liczbowy budżet YouTube oraz budżet żądań Spotify dla 50 utworów; sanitizowany zapis oraz ręczne sprzątnięcie playlist.

**Poza zakresem:** właściwi klienci integracji, UI OAuth, modele tokenów, import/eksport/synchronizacja, live secrets w CI, publiczna akceptacja providerów, Spotify Extended Quota, cykl poświadczeń Managera i revokacja grantów użytkowników.

## Architektura / Podejście

`music-map` definiuje wyłącznie symboliczne wejścia konfiguracji i bezpieczne zachowanie konsumenta. Manager pozostaje nieprzezroczystym dostawcą runtime credentials. Rzeczywiste API są sprawdzane ręcznie według trwałego runbooka, a do repo trafia tylko sanitizowana macierz wyników — nie tokeny, odpowiedzi ani identyfikatory platformowe.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Kontrakt produktu i konfiguracji | Wykonalne wymagania oraz fail-closed settings | Niespójny język PRD mógłby zachować fałszywą obietnicę Spotify |
| 2. Runbook i macierz | Powtarzalne, bezpieczne kroki oraz szablon dowodu | Procedura mogłaby wyciec token lub pominąć ważną zdolność |
| 3. Walidacja live | Wyniki obu platform i decyzja PASS/BLOCKED | Zewnętrzne ograniczenie może zatrzymać zależne wycinki |

**Wymagania wstępne:** aktywne projekty deweloperskie, poświadczenia aplikacji, dedykowane konta techniczne oraz świeże konta testowe Spotify i Google/YouTube.

**Szacowany wysiłek:** około 2–3 sesje w 3 fazach; czas zewnętrznych zgód produkcyjnych nie jest częścią estymacji.

## Otwarte ryzyka i założenia

- Google External/Testing wydaje dla scope'ów YouTube refresh tokeny o ograniczonym czasie życia; `PASS` nie dowodzi trwałej gotowości produkcyjnej.
- Spotify Development Mode ogranicza grupę użytkowników i zależy od warunków konta właściciela aplikacji.
- Aktualne możliwości i polityki providerów mogą się zmienić; runbook musi wskazywać datę oraz oficjalne źródła użyte przy próbie.
- `public=false` Spotify nie jest ścisłą kontrolą dostępu, nawet jeśli playlista nie jest widoczna w profilu i wyszukiwarce.
- Manager musi dostarczać uzgodnione symboliczne wartości runtime; szczegóły przechowywania i rotacji pozostają poza zakresem aplikacji.

## Kryteria sukcesu (podsumowanie)

- Dokumenty produktu nie obiecują zachowania niemożliwego w aktualnych API, a konfiguracja jest kompletna i nie ujawnia sekretów.
- Spotify i YouTube przechodzą wymaganą macierz na kontach technicznych oraz osobnych świeżych kontach testowych.
- Sanitizowany dowód jest kompletny, testowe zasoby usunięte, a każdy blocker zatrzymuje zależne zmiany zamiast uruchamiać fallback.
