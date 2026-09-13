<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Powiązanie kont streamingowych

- **Plan**: context/changes/streaming-account-linking/plan.md
- **Scope**: Phases 1–4 of 4 (20 automated steps complete; 10 manual steps pending)
- **Date**: 2026-09-13
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 4 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | FAIL |
| Pattern Consistency | WARNING |
| Success Criteria | WARNING |

## Findings

### F1 — Callback result can carry an access token beyond the ephemeral boundary

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Architecture
- **Location**: app/Integrations/StreamingAccounts/Actions/WithStreamingAccess.php:89
- **Detail**: The plan says `StreamingAccessResult` never contains a token and `StreamingAccessContext` cannot be returned from the callback. The port accepts a `mixed` callback result and rejects only a result that is directly a `StreamingAccessContext`. Returning `$context->accessToken`, `['context' => $context]`, or another wrapper succeeds and transports the token in `StreamingAccessResult::$value`. `__serialize()` on the context and grant blocks PHP serialization only; their public secret properties remain JSON-encodable. No test covers direct token copying, nested context return, or JSON serialization.
- **Fix A ⭐ Recommended**: Replace the arbitrary `mixed` return boundary with narrow, token-free operation/result contracts for each consumer.
  - Strength: Makes the safe output shape explicit and removes the generic secret-bearing transport path at the architecture boundary.
  - Tradeoff: Requires changing the public port and adapting current and planned S-02/S-07/S-08 consumers.
  - Confidence: HIGH — the leak follows directly from the public properties and `mixed` result path.
  - Blind spot: Future consumer result requirements are not yet fully implemented.
- **Fix B**: Add defensive JSON blocking plus recursive rejection of the current access-token value and nested secret-bearing DTOs before creating a successful result.
  - Strength: Preserves the existing callback API and blocks the most likely accidental leaks.
  - Tradeoff: Runtime inspection cannot prove that transformed or copied secret material is absent, so the architectural guarantee remains partial.
  - Confidence: MEDIUM — it catches direct and nested leakage but cannot enforce arbitrary callback behavior completely.
  - Blind spot: Token-derived strings and custom opaque objects may evade inspection.
- **Decision**: FIXED via Fix A — callback results are now limited to `void|StreamingAccessFailure`, `StreamingAccessResult` no longer carries arbitrary values, and regression tests reject direct and nested secret-bearing returns.

### F2 — OAuth attempts are consumed with a non-atomic session read-modify-write

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/StreamingAccounts/StreamingOAuthAttemptStore.php:54
- **Detail**: `consume()` reads the attempt array, removes an entry in memory, and writes the whole array back without request/session serialization. Concurrent callbacks can both validate the same state before either write completes; callbacks for different states can also overwrite each other's removals and resurrect a consumed attempt. The sequential replay test does not exercise this race.
- **Fix**: Serialize connect/callback mutation with Laravel session blocking (using bounded lock/wait times supported by the configured lock store), and add a concurrency-oriented store/route test.
  - Strength: Preserves the existing encrypted-session design while making one-time consumption and multi-tab updates linearizable.
  - Tradeoff: Adds lock-store availability and bounded-wait behavior to the request path.
  - Confidence: HIGH — the current operation is an unguarded read-modify-write over shared session state.
  - Blind spot: Actual contention and lock-store behavior have not been measured in the deployed environment.
- **Decision**: FIXED — connect and callback now use bounded per-session route locks, with a route-contract regression test covering lock and wait durations.

### F3 — Unexpected callback failures escape after state consumption

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: app/Http/Controllers/StreamingAccountOAuthController.php:72
- **Detail**: After the one-time state is consumed, unexpected container, configuration, cryptography, or database exceptions can escape as a 500. The analogous Google login callback catches `Throwable`, records only sanitized diagnostic metadata, and returns neutral UX. The streaming callback has no test for a throwing gateway or linker.
- **Fix**: Add a post-scrub orchestration exception boundary that records only exception class plus correlation ID, returns the existing neutral redirect, and test a throwing gateway/linker with secret-free logs.
  - Strength: Matches the established auth callback pattern and prevents internal failures from becoming raw 500 responses after consuming state.
  - Tradeoff: Requires careful placement so expected domain failures remain distinct and sensitive request/provider context is never logged.
  - Confidence: HIGH — the missing boundary is visible and the repository contains a directly analogous implementation.
  - Blind spot: Global production exception-reporting configuration was not exercised end-to-end.
- **Decision**: FIXED — post-state callback orchestration now has a sanitized exception boundary with class/UUID-only logging, neutral UX, and a secret-absence regression test.

### F4 — Completed Phase 2 test steps do not cover the planned failure matrix

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/StreamingAccounts/StreamingOAuthTest.php:20
- **Detail**: Progress marks Phase 2 gateway, state, confidentiality, and CAS verification complete, but the named tests omit explicit planned cases for timeout/5xx, malformed or missing provider fields, no-retry behavior, broader rate/quota mapping, callback scope/account mismatch and failure mapping, grant/context JSON or nested-result leakage, and the same-`updated_at` credential-version proof. First-insert and ownership-conflict coverage exists later in `StreamingAccountLinkingTest.php`, but the remaining matrix is absent.
- **Fix**: Add the missing failure, secrecy, and concurrency cases to the Phase 2 test suites, prioritizing F1/F2/F3 regression tests and provider malformed-response/timeout behavior.
  - Strength: Brings the checked-off verification contract back in line with the plan and protects the highest-risk boundaries found by this review.
  - Tradeoff: Non-trivial test expansion; concurrency coverage may require a lower-level deterministic seam.
  - Confidence: HIGH — the named test methods and assertions are materially narrower than the explicit plan matrix.
  - Blind spot: Live provider behavior remains outside fake-based automated tests.
- **Decision**: FIXED — gateway tests now cover transport/5xx without retry, malformed responses, invalid grants, rate limits, and ambiguous YouTube identity; callback tests cover missing scopes, invalid identity, and account replacement; CAS explicitly proves independence from unchanged `updated_at`.

### F5 — Release and UX manual verification is still pending

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: context/changes/streaming-account-linking/reviews/manual-verification.md:9
- **Detail**: All 10 manual Progress rows remain unchecked. The release-evidence artifact explicitly says no supervised schema release or live provider smoke has been performed; candidate, environment, Spotify/YouTube cycles, secret-absence observations, accessibility/responsiveness, and sign-off remain pending. This is not blind sign-off, but the change is not release-verified yet.
- **Fix**: Execute the manual UX, schema-release, live Spotify/YouTube, and secret-absence checklist for an exact committed candidate, then complete the evidence artifact without recording identifiers or secrets.
  - Strength: Supplies the real-provider and human UX evidence that automated fakes and builds cannot establish.
  - Tradeoff: Requires operator access, dedicated accounts, registered callbacks, and coordinated release infrastructure.
  - Confidence: HIGH — the Progress rows and evidence template explicitly record the work as pending.
  - Blind spot: No access to the target environment or provider accounts was available during this review.
- **Decision**: PENDING

## Verification Evidence

- `php artisan test tests/Feature/StreamingAccounts/StreamingAccountMigrationTest.php` — PASS (1 test, 7 assertions)
- `php artisan test tests/Feature/StreamingAccounts/StreamingAccountModelTest.php` — PASS (7 tests, 19 assertions)
- `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php` — PASS (5 tests, 10 assertions)
- Spotify/YouTube gateway tests — PASS (6 tests, 25 assertions)
- WithStreamingAccess and OAuth feature tests — PASS (9 tests, 70 assertions)
- Google login and PlatformAccess regressions — PASS (119 tests, 730 assertions)
- Management and authorization tests — PASS (11 tests, 78 assertions)
- Full streaming-account matrix — PASS (38 tests, 212 assertions)
- `composer test` — PASS (274 tests, 1718 assertions)
- `vendor/bin/pint --test` — PASS
- `npm run build` — PASS
- `sh scripts/verify-source-contract --worktree` — PASS
- `composer audit --locked --no-interaction && npm audit --audit-level=high` — PASS (0 advisories/vulnerabilities)
- PostgreSQL workflow contract is present and covered by `ProductionInfrastructureTest`; this review did not execute a live PostgreSQL CI runner.

## Scope Notes

- Reviewed implementation commits: `53db8c5`, `f61d3dc`, `b453d93`, `7ac0962`.
- The interleaved `playlist-link-import` commits and current unrelated worktree changes were excluded.
- No prohibited playlist/synchronization schema, OAuth dependency, access-token persistence, `auth_identities`/PlatformAccess coupling, PKCE/DPoP, or Manager implementation detail was introduced by the reviewed commits.
