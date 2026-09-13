# Powiązanie kont streamingowych — krótki plan

> Pełny plan: `context/changes/streaming-account-linking/plan.md`

## Co i dlaczego

S-04 pozwala bezpiecznie powiązać, ponownie autoryzować lub odłączyć Spotify i
YouTube. Unlink usuwa lokalny dostęp i daje S-08 kontrakt wyłączenia synchronizacji.

## Punkt wyjścia

Auth i acceptance probe są gotowe, lecz rozdzielone: `AuthIdentity` nie
przechowuje tokenów, a F-01 obsługuje tylko technical/tester credentials.
Brakuje user OAuth, modelu połączeń, UI i rekordów synchronizacji.

## Pożądany stan końcowy

Użytkownik przechodzi link/relink/unlink na osobnym ekranie. Spotify zapisuje
`account_id`, a YouTube wiąże kanał wybrany podczas consent/login providera.
Baza zawiera tylko zaszyfrowany refresh token. Nieważny grant zachowuje
etykietę i wymaga reconnect.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Własność | Po jednym koncie providera na usera, bez współdzielenia | Zapobiega konfliktom synchronizacji |
| Scope | Pełny minimalny read/write od początku | S-07/S-08 nie przebudują grantu |
| Spotify | Scope obejmuje collaborative read | Przygotowuje wspólne playlisty |
| Storage | Tylko szyfrowany refresh token | Minimalizuje wartość wycieku |
| OAuth | Własne wąskie gatewaye HTTP | Zachowuje exact kontrakty |
| State | Wiele prób user/provider/purpose | Nie koliduje z Google login |
| YouTube | Wybór kanału podczas consent/login | Zwykły grant identyfikuje kanał wybrany po stronie providera |
| Reconnect | Update tego samego konta | Nie tworzy duplikatów |
| Unlink | Lokalny delete, revoke po commit | Natychmiast kończy dostęp |
| Sync | Transakcyjny seam z no-op w S-04 | Nie projektuje tabel S-08 |
| UI | Ekran Integracje i modal skutków | Stabilne miejsce zarządzania |
| Akceptacja | Automaty plus live smoke | Weryfikuje realny consent |

## Zakres

**W zakresie:** model połączeń, OAuth Spotify/YouTube, szyfrowany refresh token,
wiele stanów, providerowy wybór kanału, link/reconnect/unlink, revoke/instrukcje, UI,
SQLite/PostgreSQL i live smoke.

**Poza zakresem:** playlisty, import/eksport, harmonogram synchronizacji,
YouTube quota, wiele kont jednego providera, access-token storage, zmiany F-01,
community OAuth package i internals Managera.

## Architektura / Podejście

`Controller → provider gateway → domenowa akcja → encrypted StreamingAccount`.
Jawna weryfikacja połączenia wykonuje refresh poza transakcją i atomowo zapisuje
rotację albo reconnect. `WithStreamingAccess` udostępnia access token wyłącznie
w pamięci callbacku po udanym CAS. Unlink:
`disable-sync → delete credential → commit → best-effort revoke`.

Manager wyłącznie przechowuje i dostarcza konfigurację platformową runtime
(client ID, client secret, callback URI i inne sekrety). Laravel prowadzi OAuth
użytkownika oraz wykonuje link/relink/unlink, refresh, weryfikację konta i
operacje na playlistach.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Domena i storage | Model, migracja i szyfrowanie | Rotacja APP_KEY |
| 2. OAuth i linkowanie | Gatewaye, state i providerowy wybór kanału | Kolizja callbacku |
| 3. Unlink i UI | Modal, revoke i seam S-08 | Grant po unlink |
| 4. Utwardzenie | CI, schema-release i live smoke | Fake vs consent |

**Wymagania wstępne:** aktywne projekty i konta testowe, exact HTTPS callbacki,
gotowe F-01/S-01 oraz zewnętrzna bramka schema-release.
**Szacowany wysiłek:** około 5–7 sesji w czterech fazach plus bramka live.

## Otwarte ryzyka i założenia

- Spotify okresowo wymaga ponownej autoryzacji refresh tokenu.
- Revoke Google może objąć grant projektu, lecz nie login identity/sesję.
- Spotify wymaga ręcznego `Remove Access` po lokalnym unlink.
- Callback YouTube wymaga dokładnie jednego kanału dla otrzymanego grantu.
- S-08 zastępuje no-op przed utworzeniem zależnych rekordów.

## Kryteria sukcesu (podsumowanie)

- User zarządza jednym kontem każdej platformy bez dostępu do cudzych.
- Tokeny, code i payloady nie występują jako plaintext w bazie, HTML ani logach.
- Unlink usuwa lokalny dostęp, a macierz przechodzi automatycznie i live.
