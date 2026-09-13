# Gotowość dostępu do Spotify i YouTube — krótki plan

> Pełny plan i normatywny kontrakt:
> `context/changes/platform-access-readiness/plan.md`

## Co i dlaczego

`music-map` implementuje publiczny adapter
`music-map.platform-access.v1`, aby Manager mógł bezpiecznie potwierdzić
dostęp technicznych i testowych kont Spotify oraz YouTube przed budową importu,
linkowania i eksportu.

Manager jest zewnętrznym PaaS i inicjuje interakcję. Aplikacja nie zarządza
OAuth, trwałym przechowywaniem, dostarczaniem, revoke ani recovery poświadczeń;
udostępnia tylko `php artisan platform-access:probe`, wykonuje minimalne
operacje providera, zwraca zamknięty JSON i przekazuje ewentualny replacement
refresh token przez prywatny rotation sink Managera.

## Punkt wyjścia

Repo nie ma jeszcze kodu Spotify/YouTube ani komendy probe.
`config/services.php` zawiera klienta Google login, który YouTube
platform-access współdzieli bez zmiany redirectu lub semantyki logowania.
Publiczny kontrakt PaaS jest już kompletny, dlatego jego minimalna część
interoperacyjna została utrwalona w pełnym planie razem z exact scope.

## Pożądany stan końcowy

- Exact invocation przyjmuje provider `spotify|youtube`, principal
  `technical|tester`, wymagane `--write --format=json` oraz globalne
  `--no-ansi --no-interaction`.
- `technical` czyta wyłącznie techniczne env, odświeża token i dowodzi
  identity/read bez mutacji.
- `tester` czyta wyłącznie zamkniętą sesję z publicznego locatora, zapisuje
  trzy elementy i zawsze próbuje cleanup.
- Sukces i błąd mają exact pola, właściwy strumień i exit `0`, `1` albo
  `2`; capability sets są stałe.
- Niepoprawne raw argv jest przechwytywane w `artisan` przed rozwiązaniem nazwy
  komendy i standardowym bindem Symfony, więc również nieopcyjny token lub
  nieznana opcja przed nazwą zwraca kontraktowy JSON/exit `2`, bez tekstu CLI.
- Probe nie zapisuje rotowanego refresh tokenu trwale ani nie ujawnia go w
  outputach lub logach; przekazuje go wyłącznie przez prywatny sink Managera.

## Kluczowe decyzje

| Decyzja | Wybór |
| --- | --- |
| Kierunek | Manager uruchamia `music-map`; aplikacja nie wywołuje Managera |
| Entrypoint | `php artisan platform-access:probe` |
| Protokół | `music-map.platform-access.v1` |
| Principals | Rozłączne `technical` i `tester`, bez fallbacku |
| Spotify scope | `playlist-modify-private playlist-read-private user-read-private` |
| YouTube scope | `https://www.googleapis.com/auth/youtube` |
| YouTube client | Istniejące `GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET` |
| Technical | Refresh exchange + identity/read, bez mutacji |
| Tester | Refresh exchange + identity/read/write/cleanup na 3 kanonicznych elementach: Spotify `spotify:track:` + 22 base62, YouTube 11 znaków `[A-Za-z0-9_-]` |
| Fixture | Osobna, prywatna i początkowo pusta playlista dla Spotify i YouTube; restore do pustego stanu |
| Spotify owner | `/me.account_id` wiąże principal; efemeryczne `/me.id` jest porównywane wyłącznie z `playlist.owner.id` |
| Rotacja | Efemeryczny prywatny sink Managera; błąd zapisu daje `refresh-token-rotation-required` |
| Wyjście | Zamknięty JSON, exact capabilities i exit `0/1/2` |
| Provider API | Jawne endpointy, bounded pagination i deterministyczne mapowanie błędów |

## Fazy

| Faza | Wynik |
| --- | --- |
| 1. Publiczny protokół i granice wejścia/wyjścia | Typowane config, invocation, sesja, odpowiedzi i exit codes |
| 2. Probe'y providerów i macierze principal | Spotify/YouTube z exact identity, scope, operacjami i cleanup |
| 3. Komenda, źródło i akceptacja interoperacyjna | Entrypoint, source contract, pełne bramki i artefakt gotowy do przekazania PaaS |

## Poza zakresem

OAuth callback, PKCE, trwałe przechowywanie i recovery poświadczeń, revoke,
komendy oraz internals Managera, UI, migracje, tokeny użytkowników, domenowy
import/eksport/synchronizacja i egzekwowanie dziennej kwoty YouTube. Aplikacja
zapisuje replacement token wyłącznie do publicznego locatora prywatnego sinka.

## Kryteria sukcesu

- Cztery kombinacje provider×principal zwracają exact capability sets.
- Błędy wejścia kończą się przed siecią, cleanup ma pierwszeństwo, a obie
  zarezerwowane fixture wracają do potwierdzonego pustego stanu; niejednoznaczna
  mutacja YouTube zawsze daje `cleanup-failed`.
- Test subprocess dowodzi zachowania prawdziwego `php artisan` także dla
  nieznanych opcji, argumentów pozycyjnych i nieopcyjnego tokenu przed nazwą
  komendy, bez zmiany zachowania innych komend Artisan.
- Pełny PHPUnit, Pint, build i source contract przechodzą bez prawdziwej sieci
  i sekretów.
- Exact commit przechodzi literalny przegląd kontraktu i wszystkie bramki,
  tworząc artefakt gotowy do przekazania PaaS. Promocja, OAuth i live acceptance
  pozostają wyłącznie w planie Managera.
