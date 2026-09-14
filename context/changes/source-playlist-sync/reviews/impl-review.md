<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Synchronizacja playlisty źródłowej

- **Plan**: context/changes/source-playlist-sync/plan.md
- **Scope**: Phases 1–6 of 6
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Findings**: 3 critical, 5 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | FAIL |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

## Findings

### F1 — Pełny retry YouTube omija zapisany checkpoint

- **Severity**: ❌ CRITICAL
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Plan Adherence
- **Location**: app/Actions/PlaylistSync/RunPlaylistSynchronization.php:68
- **Detail**: Po częściowo udanym zapisie YouTube run pozostaje `running` i ma checkpoint, ale kolejna próba ponownie wyznacza kierunek ze starej bazy i aktualnego, częściowo zmienionego źródła. `DeterminePlaylistSyncDirection` wybiera wtedy pull, więc writer nie dostaje szansy rozpoznać stan `before`/`after` i wznowić mutacji. Bank może zostać zastąpiony częściowym stanem źródła. Test przerwań wywołuje writer bezpośrednio (`tests/Feature/PlaylistSync/PlaylistSynchronizationFailureTest.php:160`), dlatego zielony suite nie dowodzi retry przez coordinator/job wymaganego przez fazy 3, 4 i 6.
- **Fix ⭐ Recommended**: Dla runu `running` z checkpointem i kierunkiem push wznawiać writer przed zwykłym obliczaniem kierunku, a test przerwań przeprowadzić przez pełny coordinator/job.
  - Strength: Przywraca zaplanowaną semantykę trwałego operation ID i stanów `before`/`after`, bez akceptowania częściowego źródła jako wyniku.
  - Tradeoff: Koordynator musi jawnie rozróżniać własny częściowy zapis od zewnętrznego driftu.
  - Confidence: HIGH — writer ma już wymagany checkpoint i logikę rozpoznawania stanów; pomija ją warstwa wyżej.
  - Blind spot: Wymaga sprawdzenia zachowania dla checkpointu po zmianie banku i po wyczerpaniu admission.
- **Decision**: FIXED — checkpointed YouTube push now resumes through the coordinator using the original bank snapshot; full-flow regression added and verified.

### F2 — Edycja banku podczas zewnętrznego push utrwala rozjazd

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Actions/PlaylistSync/CompletePlaylistSyncRun.php:29
- **Detail**: Bank jest snapshotowany pod blokadą, lecz blokada kończy się przed HTTP. Jeżeli użytkownik zmieni bank z A na B podczas zapisu A do dostawcy, `CompletePlaylistSyncRun` pobiera aktualne B, zapisuje je jako `baseline_bank_fingerprint`, zapisuje A jako `baseline_source_fingerprint` i zeruje `bank_content_edited_at`. Następny run uzna obie strony za niezmienione względem ich oddzielnych baz, więc trwały rozjazd B/A zostanie błędnie uznany za sukces. Analogiczny wyścig istnieje przy no-op.
- **Fix ⭐ Recommended**: W finalizacji pod blokadą porównać aktualny fingerprint banku z fingerprintem użytym do decyzji; przy zmianie nie kasować markera ani nie ustanawiać rozbieżnych baz, tylko zachować wspólny stan sprzed edycji i zlecić kolejne uzgodnienie.
  - Strength: Zapobiega fałszywemu sukcesowi i zachowuje lokalną edycję do następnego push.
  - Tradeoff: Potrzebny jest jawny stan/retry dla zmiany wykrytej po zakończeniu HTTP.
  - Confidence: HIGH — obecna finalizacja nie wykonuje żadnej rewalidacji fingerprintu po writerze.
  - Blind spot: Należy ustalić, czy UI ma pokazać sukces pierwszego push, czy stan oczekującego ponowienia.
- **Decision**: FIXED — finalization now preserves the written baseline and local edit marker, then queues one pending reconciliation run when the bank changes during provider I/O.

### F3 — Unlink może zostać cofnięty przez trwający lub spóźniony job

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: app/Actions/PlaylistSync/DisableAccountPlaylistSynchronizations.php:23
- **Detail**: Seam unlink anuluje tylko runy `pending`. Spóźniony job anulowanego runu może przejść przez `missing-access`, a `FailPlaylistSyncRun` zmienić `cancelled/disabled` na `failed/attention`. Job już wykonujący HTTP może natomiast po unlink wejść do `CompletePlaylistSyncRun` i ustawić synchronizację z powrotem na `enabled`. Test lifecycle sprawdza sam seam, ale nie uruchomienie joba po odłączeniu. Narusza to kontrakt trwałego wyłączenia i brak automatycznego wznowienia po reconnect.
- **Fix ⭐ Recommended**: Uczynić `cancelled` i `disabled` terminalnymi guardami w prepare/fail/complete, weryfikować zgodność account ID przed write i nigdy nie reaktywować synchronizacji po unlink; dodać testy spóźnionego oraz trwającego joba.
  - Strength: Zachowuje granicę cyklu życia konta nawet przy kolejce i równoległym HTTP.
  - Tradeoff: Zewnętrznej mutacji już rozpoczętej nie zawsze da się cofnąć; wymagane jest jednoznaczne rozliczenie runu po powrocie.
  - Confidence: HIGH — wszystkie ścieżki nadpisujące stan są widoczne i nie sprawdzają `disabled/cancelled`.
  - Blind spot: Nie zweryfikowano gwarancji anulowania aktywnego requestu przez samych dostawców.
- **Decision**: FIXED — unlink now cancels pending and running runs; coordinator, failure, and completion paths preserve terminal cancelled/disabled state, with late-job and in-flight-provider regressions.

### F4 — Kody limitów YouTube prowadzą do błędnych działań naprawczych

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/PlaylistSync/Providers/YouTubeSourcePlaylistReader.php:149
- **Detail**: Reader i writer mapują każdy HTTP 403 na `forbidden`, mimo że istniejące integracje rozpoznają z payloadu `quotaExceeded`, `dailyLimitExceeded`, `rateLimitExceeded` i `userRateLimitExceeded`. Taki limit staje się terminalnym błędem z komunikatem o reconnect zamiast retry/quota. Dodatkowo odmowa wewnętrznego admission zwraca `OverLimit` (`YouTubeSourcePlaylistWriter.php:64`), co UI tłumaczy jako playlistę większą niż 20 pozycji (`resources/views/playlists/_synchronization.blade.php:27`).
- **Fix ⭐ Recommended**: Wprowadzić rozłączne, reason-aware kody dla provider quota/rate limit, odmowy admission i limitu 20 pozycji oraz odpowiadające im retry i komunikaty.
  - Strength: Wykorzystuje wzorzec z `ProviderImportFailureMapper` i daje użytkownikowi prawidłową ścieżkę odzyskania.
  - Tradeoff: Poszerza enum, mapowanie joba i macierz testów UI/providerów.
  - Confidence: HIGH — obecne mapowania i błędny tekst są bezpośrednio widoczne w kodzie.
  - Blind spot: Dokładna polityka retry po dziennym limicie wymaga decyzji produktowej.
- **Decision**: FIXED — YouTube quota, rate limiting, application write admission, and playlist-size limits now use distinct codes, policies, and safe UI messages; reader, writer, admission, and UI regressions added.

### F5 — Synchroniczne endpointy providerów nie mają throttlingu

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: routes/web.php:81
- **Detail**: `prepare` i `confirm` wykonują refresh OAuth oraz wielokrotne żądania API dostawcy w requestach HTTP, ale nie mają limitera. Sąsiednie integracje importu, OAuth i export review używają dedykowanych limiterów. Zweryfikowany użytkownik może seryjnie zużywać quota i blokować workery; token preview jest usuwany dopiero po udanym confirm.
- **Fix**: Dodać limiter per user i provider do prepare/confirm oraz atomowo konsumować lub blokować token preview podczas confirm.
- **Decision**: FIXED — prepare/confirm now share a per-user/provider limiter and confirmation atomically consumes the scoped preview token under a cache lock; rate-limit and one-use regressions added.

### F6 — Widok edycji i listener logowania wykonują nieograniczoną pracę

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: resources/views/playlists/_synchronization.blade.php:2
- **Detail**: Widok ładuje całą historię `synchronization.runs` i sortuje ją w PHP tylko po to, aby pobrać najnowszy run, choć endpoint statusu używa ograniczonego zapytania. Listener logowania robi `pluck()` wszystkich kwalifikujących się synchronizacji użytkownika i dla każdej synchronicznie otwiera transakcję/dispatch, bez batch limitu. Obie ścieżki rosną bez ograniczenia w requestach użytkownika.
- **Fix ⭐ Recommended**: Użyć relacji/query `latestOfMany` dla widoku oraz ograniczyć listener do batcha z kontynuacją w kolejce.
  - Strength: Wiąże koszt renderu i logowania ze stałym limitem, zgodnie z istniejącym wzorcem schedulerowego batchowania.
  - Tradeoff: Listener potrzebuje mechanizmu kontynuacji, aby nie pominąć dalszych playlist.
  - Confidence: HIGH — obie kolekcje są obecnie pobierane bez limitu.
  - Blind spot: Nie zmierzono rzeczywistej liczby playlist/runów na użytkownika w produkcji.
- **Decision**: FIXED — the edit panel now loads a `latestOfMany` run and login dispatch is capped by the configured synchronization batch size, with relationship and batch regressions.

### F7 — Scheduler może przekroczyć gwarancję czterech godzin

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: app/Console/Commands/DispatchDuePlaylistSynchronizations.php:41
- **Detail**: Następny termin może wynosić dokładnie `now()+240 min`, ale command działa co pięć minut (`routes/console.php:15`). Faktyczny dispatch może więc nastąpić niemal po 245 minutach. Test sprawdza wyłącznie zapisany timestamp, a nie maksymalne opóźnienie do kolejnego ticka.
- **Fix**: Odjąć częstotliwość schedulera od maksymalnego jitteru (lub zwiększyć częstotliwość) i przetestować najgorsze wyrównanie ticka.
- **Decision**: FIXED — deterministic jitter now reserves the scheduler's five-minute cadence, with the 240-minute contract tested against a 235-minute stored deadline.

### F8 — Dziesięć ręcznych kryteriów pozostaje niezweryfikowanych

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: context/changes/source-playlist-sync/plan.md:537
- **Detail**: Progress ma 30/40 pozycji zakończonych. Wszystkie 10 kryteriów ręcznych faz 1–6 pozostaje `[ ]`, w tym live smoke testy Spotify/YouTube i cleanup, mimo że `change.md` miał status `implemented`. Plan wprost stwierdza, że zmiana jest gotowa do przeglądu dopiero po automatycznej macierzy i ręcznych smoke testach.
- **Fix ⭐ Recommended**: Wykonać checklistę manualną i smoke test z `smoke-test.md`, zapisać bezpieczne dowody, a status gotowości utrzymywać zgodnie z rzeczywistym Progress.
  - Strength: Domyka jedyne kryteria wymagające prawdziwych providerów i oględzin UI.
  - Tradeoff: Wymaga kontrolowanych kont, danych testowych oraz ręcznego cleanupu.
  - Confidence: HIGH — wszystkie manualne checkboxy są jawnie otwarte.
  - Blind spot: Brak dostępu do zewnętrznych kont uniemożliwia niezależne wykonanie w tej recenzji.
- **Decision**: ACCEPTED — user accepts the current risk and will execute the manual and live-provider verification later; Progress remains unchecked until evidence exists.

### F9 — `.env.example` pomija wymagany scope Spotify

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: .env.example:71
- **Detail**: `SPOTIFY_TECHNICAL_SCOPES` nie zawiera `playlist-read-collaborative`, chociaż domenowy `StreamingProvider::Spotify->requiredScopes()` i README go wymagają. To rozjeżdża publiczny kontrakt konfiguracji z implementacją fazy 6.
- **Fix**: Dodać `playlist-read-collaborative` do bezpiecznej listy scope w `.env.example`.
- **Decision**: FIXED — added `playlist-read-collaborative` to the safe Spotify technical scope placeholder; user confirmed production already includes it.

## Verification Evidence

- `composer test`: PASS — 569 tests, 555 passed, 14 skipped, 3310 assertions.
- `vendor/bin/pint --test`: PASS.
- `npm run build`: PASS — Vite 8.2.2 production build.
- `sh scripts/verify-source-contract --worktree`: PASS.
- Playlist-sync focused review run: PASS — 20 tests, 188 assertions (independent quality scan).
- PostgreSQL-specific playlist-sync tests are among the skipped tests under the default SQLite configuration; current execution did not independently reproduce the PostgreSQL CI service.
- Manual Progress items: 0/10 complete.

## Scope Notes

- The `Czego NIE robimy` boundaries were respected.
- No secret leakage, raw Blade/XSS, permissive CORS, or cross-user authorization bypass was found.
- The pre-existing user modification in `context/foundation/roadmap.md` was excluded from the review and left untouched.

## Triage Summary

- **Fixed**: F1, F2, F3, F4, F5, F6, F7, F9 (8)
- **Accepted**: F8 — manual and live-provider verification deferred by the user (1)
- **Pending**: none
- **Final verification**: `composer test` PASS (579 tests, 565 passed, 14 skipped, 3363 assertions); Pint PASS; Vite build PASS; source contract PASS; `git diff --check` PASS.
