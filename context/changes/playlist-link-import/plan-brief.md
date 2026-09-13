# Import playlisty z linku — krótki plan

> Pełny plan: `context/changes/playlist-link-import/plan.md`

## Co i dlaczego

S-02 pozwala zalogowanemu użytkownikowi zachować playlistę Spotify lub YouTube
w prywatnym banku z kanonicznego linku. Import jest atomowy, ograniczony do 20
pozycji i zwraca naprawialną przyczynę odmowy zamiast ogólnego błędu.

## Punkt wyjścia
Auth, pusty prywatny bank i acceptance probe obu platform są gotowe. Brakuje
modelu playlist, runtime readerów, formularza importu i cyklu świeżości danych;
probe F-01 nie jest klientem produktu.

## Pożądany stan końcowy

Publiczny YouTube importuje się przez API key bez powiązanego konta, a Spotify
przez efemeryczny dostęp z aplikacyjnego kontraktu S-04. Użytkownik widzi prywatną
kartę playlisty, a reimport odświeża ten sam rekord bez utraty poprzedniego
snapshotu przy awarii.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Równoległość | S-02 i S-04 po wspólnym kontrakcie | Oddziela playlisty od OAuth |
| Tokeny | S-04 szyfruje refresh token; S-02 nie utrwala tokenów | Rozdziela poświadczenie od logiki importu |
| YouTube | Ograniczony API key | Publiczny import nie wymaga linked account |
| Reimport | Atomowa podmiana rekordu | Nie tworzy duplikatów ani partial state |
| Limit | Odmowa po 21. pozycji | Nie obcina źródła poza gwarancją MVP |
| Pozycje | Kolejność, duplikaty i placeholdery | Nie ukrywa różnic względem źródła |
| Wykonanie | Synchroniczne i ograniczone | Prosty UX i bounded request |
| Dane | Minimalny snapshot do przyszłego dopasowania | Bez globalnej tabeli utworów |
| URL | Ścisła allowlista HTTPS | Odcina redirect i SSRF |
| Błędy | Zamknięta taksonomia | Daje bezpieczną przyczynę i następny krok |
| UI | Karta bez listy pozycji | Nie przejmuje S-03 |
| YouTube policy | Refresh przed 30 dniami | Nie pokazuje starych danych jako aktualnych |

## Zakres
**W zakresie:** modele playlist i pozycji, parser URL, publiczny reader YouTube,
Spotify przez `WithStreamingAccess` z S-04, atomowy import/reimport, karty banku, typowane odmowy,
cykl świeżości YouTube, atrybucja i oba silniki DB.

**Poza zakresem:** lista/edycja utworów, ręczne tworzenie, eksport, matching,
pełna synchronizacja, playlisty ponad 20 pozycji, token storage i Manager.

## Architektura / Podejście

`URL parser → access selector → provider reader → normalized snapshot → atomic
replace → private bank card`. YouTube używa API key; Spotify reader działa w
callbacku `WithStreamingAccess` i dostaje access token wyłącznie w pamięci.
Sieć kończy się przed transakcją playlisty.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Kontrakty i dane | Prywatny, atomowy snapshot | Wspólny provider type z S-04 |
| 2. Publiczny YouTube | Formularz do karty banku | Normalizacja i partial state |
| 3. Świeżość YouTube | Cykl 28/30 dni i atrybucja | Interpretacja polityki API |
| 4. Spotify | Import owner/collaborator | Brak kontraktu `WithStreamingAccess` z S-04 |
| 5. Utwardzenie | PostgreSQL, repo gates i release | Shared-file conflicts |

**Wymagania wstępne:** F-01 i S-01 są ukończone. Faza 4 wymaga ukończonego
S-04 i aplikacyjnego kontraktu `WithStreamingAccess`. Szacowany
wysiłek: około 6–9 sesji w pięciu fazach plus bramka kontraktu.

## Otwarte ryzyka i założenia

- S-04 musi zapisać replacement refresh token przez CAS przed operacją importu.
- Manager dostarcza wyłącznie konfigurację aplikacji providera i nie wykonuje
  OAuth użytkownika ani logiki playlist.
- Osobny Manager-owned hand-off musi opublikować i dostarczyć ograniczony
  `YOUTUBE_API_KEY`; wartość pozostaje poza repozytoriami i protokołem probe.
- Retencja YouTube wymaga akceptacji właściciela/policy review; to nie porada prawna.
- Konflikty we współdzielonych plikach wymagają jednego integratora.

## Kryteria sukcesu (podsumowanie)

- Publiczny YouTube i dostępny Spotify importują 0–20 pozycji bez partial state.
- Reimport odświeża ten sam rekord, zachowując kolejność, duplikaty i placeholdery.
- Stare dane YouTube nie są pokazywane jako aktualne; aplikacja nie ujawnia
  sekretów ani surowych payloadów/URL-i, poza kanonicznym linkiem źródła w karcie.
