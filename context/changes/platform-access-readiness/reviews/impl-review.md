<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Gotowość dostępu do Spotify i YouTube

- **Plan**: context/changes/platform-access-readiness/plan.md
- **Scope**: Phases 1–3 of 3
- **Date**: 2026-09-13
- **Verdict**: APPROVED after triage
- **Findings**: 1 critical, 2 warnings, 0 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — YouTube cleanup can discard known inserted item IDs

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; the fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/PlatformAccess/YouTubeProbe.php:314
- **Detail**: Each successful insert returns an ID that the plan requires the probe to retain for cleanup because one bounded list cannot prove that a delayed mutation is absent. When the first cleanup list succeeds, lines 327–329 replace the retained `$knownItemIds` with only the IDs currently visible in that response. A temporarily invisible successful insert is therefore not deleted; a final temporarily empty list can still let cleanup appear successful, leaving the fixture mutated and returning a category other than the mandatory `cleanup-failed`.
- **Fix**: Delete the deduplicated union of `$knownItemIds` and IDs visible in the cleanup listing, and add a regression test where successful inserts return IDs while cleanup scans temporarily omit them.
- **Decision**: FIXED — cleanup now deletes the deduplicated union of insertion-returned and currently visible item IDs; regression coverage proves known IDs are deleted when bounded scans temporarily omit them.

### F2 — Symfony command abbreviations bypass raw-argv preflight

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: artisan:24
- **Detail**: Preflight runs only when the exact token `platform-access:probe` is present. Symfony resolves abbreviations such as `platform-access:p`, `platform-a:p`, `pla:p`, and `pl:p` to the probe command. The verified command `php artisan platform-access:p --provider spotify --principal technical --write --format json --no-ansi --no-interaction` bypasses raw validation and returns `invalid-configuration`, proving that dispatch occurred; with valid runtime configuration it could reach provider calls instead of failing as `invalid-invocation` before the network.
- **Fix**: Detect and reject every command token that Symfony can resolve as an abbreviation of this entrypoint before `handleCommand()`, then add subprocess regressions for abbreviated names with separated option values.
  - Strength: Restores the contract's exact invocation grammar and pre-network rejection guarantee.
  - Tradeoff: The small front-controller matcher must remain aligned with Symfony's namespace/command abbreviation behavior.
  - Confidence: HIGH — the bypass was reproduced against the current dependency version and its dispatch result is observable.
  - Blind spot: Future Symfony abbreviation semantics could change and require the regression matrix to expand.
- **Decision**: FIXED — raw preflight now recognizes Symfony-style namespace/command abbreviations and subprocess coverage rejects four confirmed abbreviations plus separated option values before dispatch.

### F3 — Orchestrator no-fallback follow-up lacks its promised regression test

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; the fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Console/ProbePlatformAccessTest.php:66
- **Detail**: The earlier Phase 1 review explicitly deferred an orchestration-level proof to Phase 3: make the selected principal source invalid while the alternate source is valid, then prove no fallback occurs. The Phase 3 invalid-configuration test does not provide a valid tester session, and the invalid-session test does not provide valid technical configuration. The ternary in `ProbePlatformAccess.php` currently has no fallback, but the promised regression proof remains absent.
- **Fix**: In both invalid-source command tests, configure the alternate principal source as valid and retain the assertions that dispatch/network never occurs and the selected-source error is returned.
- **Decision**: FIXED — both selected-source failure tests now provide a valid alternate principal source while retaining the selected error and no-dispatch assertions.

## Automated verification evidence

| Command | Result | Output |
|---------|--------|--------|
| `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeInputTest.php` | PASS | 36 tests, 99 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/ProbeOutputTest.php` | PASS | 19 tests, 162 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/PrincipalBoundaryTest.php` | PASS | 2 tests, 6 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/RefreshTokenRotationSinkTest.php` | PASS | 4 tests, 16 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/SpotifyProbeTest.php` | PASS | 7 tests, 37 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/YouTubeProbeTest.php` | PASS | 5 tests, 33 assertions |
| `php artisan test tests/Unit/Integrations/PlatformAccess/ProviderFailureMapperTest.php` | PASS | 14 tests, 87 assertions |
| `php artisan test tests/Feature/Auth/GoogleAuthenticationTest.php` | PASS | 13 tests, 121 assertions |
| `php artisan test tests/Feature/Console/ProbePlatformAccessTest.php` | PASS | 13 tests, 114 assertions |
| `composer test` | PASS | 181 tests, 1255 assertions after triage fixes |
| `vendor/bin/pint --test` | PASS | no formatting issues |
| `npm run build` | PASS | Vite production build completed |
| `sh scripts/verify-source-contract --worktree` | PASS | source contract passed |

## Manual verification

- `1.4 Potwierdzić zgodność lokalnego kontraktu z publikacją PaaS`: CONFIRMED by the user.
- `2.5 Potwierdzić granicę PaaS i semantykę cleanup`: CONFIRMED by the user.
- `3.4 Potwierdzić gotowość artefaktu do przekazania PaaS`: CONFIRMED by the user; the defects discovered by this review were fixed during triage and all repository gates were rerun successfully.

## Triage summary

- **Fixed**: F1, F2, F3 (3)
- **Accepted as rule**: none
- **Skipped**: none
