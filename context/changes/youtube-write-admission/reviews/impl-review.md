<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Wspólne dopuszczenie zapisów YouTube — plan implementacji

- **Plan**: context/changes/youtube-write-admission/plan.md
- **Scope**: Phases 1–3 of 3
- **Date**: 2026-09-14
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 2 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — Persisted admission time does not preserve the actual instant

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php:137
- **Detail**: The action creates `$now` in `America/Los_Angeles` at line 85 and writes it directly to `admitted_at`. The migration defines `admitted_at` with `timestamp()`, which PostgreSQL compiles to `timestamp without time zone`. Eloquent formats the Pacific Carbon value without its offset, then hydrates it under the application's UTC timezone. For example, the instant `2026-09-14T12:00:00Z` is stored as `2026-09-14 05:00:00` and read as 05:00Z, seven hours early. No test round-trips this field as an instant.
- **Fix**: Persist `$now->utc()` and add a test that round-trips `admitted_at` against the original instant.
- **Decision**: FIXED

### F2 — Ambiguous-commit recovery is named but not exercised

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:308
- **Detail**: The plan explicitly requires recovery after an ambiguous commit. The test performs an ordinary successful admission, starts a fresh process, and retries the same key. That proves post-commit idempotency, but it never makes the commit persist while the caller observes a lost or unavailable acknowledgement, so the planned fault scenario remains unproved.
- **Fix**: Add a deterministic fault seam or PostgreSQL test harness that persists the reservation while making the first caller observe an ambiguous outcome, then assert that retry returns `admitted-existing` with one ledger row and one counter increment.
  - Strength: Directly proves the failure mode named by the plan instead of inferring it from an ordinary restart.
  - Tradeoff: Requires a carefully scoped fault-injection seam or lower-level connection harness.
  - Confidence: HIGH — the current test contains no ambiguous outcome or lost acknowledgement.
  - Blind spot: The best injection point depends on how future consumers classify post-commit connection failures.
- **Decision**: FIXED

### F3 — PostgreSQL criteria were skipped in the review environment

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:35
- **Detail**: The fresh review command exited successfully but skipped all 8 PostgreSQL tests because this environment was not using PostgreSQL with `pcntl`. The plan marks both automated PostgreSQL races and the disposable PostgreSQL manual check complete, but the reviewed diff contains no persisted run log. CI is configured to run the full suite with PostgreSQL and `pcntl`, so this is a local evidence limitation rather than a discovered behavior failure.
- **Fix**: Attach a green PostgreSQL CI/job URL or disposable-run transcript to the change evidence.
- **Decision**: ACCEPTED — User accepted the risk of proceeding without fresh PostgreSQL execution evidence in this review environment.

### F4 — Migration constraint helper can accept unrelated failures

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionMigrationTest.php:78
- **Detail**: `expectDatabaseException()` accepts every `Throwable` except PHPUnit's assertion failure. A PHP error or unrelated runtime exception inside a callback would therefore make a schema-constraint assertion pass without proving that the database rejected the write.
- **Fix**: Require `Illuminate\Database\QueryException` and, where portable, assert the relevant SQLSTATE or constraint.
- **Decision**: FIXED

### F5 — Early PostgreSQL-test failures can leave children unreaped

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:398
- **Detail**: Cleanup calls `pcntl_waitpid(..., WNOHANG)` once and immediately deletes the file-based barrier directory. If the parent fails before its normal blocking wait, a child may still be running, race against deleted IPC files, or contaminate a later test. An older concurrency test shares this weakness, so it is not a new pattern inconsistency.
- **Fix**: Release barriers, then boundedly terminate and blocking-reap every known child before deleting the IPC directory.
- **Decision**: FIXED

## Verification Evidence

| Command | Result |
|---------|--------|
| `php artisan test tests/Unit/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionContractTest.php` | PASS — 10 tests, 20 assertions |
| `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionMigrationTest.php` | PASS — 1 test, 10 assertions |
| `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php` | PASS — 19 tests, 48 assertions after F1 |
| `php artisan test tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php` | INCONCLUSIVE — 8 tests skipped |
| `composer test` | PASS — 493 tests, 482 passed, 11 skipped, 2847 assertions after triage |
| `vendor/bin/pint --test` | PASS |
| `npm run build` | PASS |
| `sh scripts/verify-source-contract --worktree` | PASS |

## Triage Summary

- **Fixed**: F1, F2, F4, F5
- **Accepted**: F3 — proceeded without fresh PostgreSQL execution evidence in the review environment
- **Pending**: none
