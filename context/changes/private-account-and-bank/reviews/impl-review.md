<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Private Account and Playlist Bank Entry

- **Plan**: `context/changes/private-account-and-bank/plan.md`
- **Scope**: Phases 1-5 of 5
- **Date**: 2026-09-12
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 4 warnings, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Google OAuth endpoints have no request throttling

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `routes/web.php:10`
- **Detail**: Both public Google OAuth routes use only the `guest` middleware. Live route inspection confirms that neither route has a throttle middleware. A client can repeatedly obtain a fresh session/state and submit a callback with an invalid authorization code, forcing repeated outbound provider exchanges. The password login path has an explicit limiter, but the externally I/O-bound OAuth path does not.
- **Fix**: Define a dedicated Google OAuth limiter, attach it to the redirect and callback routes, and add a feature test proving requests are rejected before provider or resolver work after the limit.
  - Strength: Bounds request amplification at the application boundary and follows the existing named login-limiter pattern in `FortifyServiceProvider`.
  - Tradeoff: The key and threshold must balance abuse resistance against users behind shared IP addresses.
  - Confidence: HIGH — route middleware was verified with `php artisan route:list --path=auth/google -vv`.
  - Blind spot: Upstream rate limiting at the public edge was not measured; relying on it would still leave the application contract implicit.
- **Decision**: FIXED — added a dedicated per-IP Google OAuth limiter to both routes and verified the provider/resolver boundary with a throttling feature test

### F2 — Unexpected Google callback failures are silently discarded

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: `app/Http/Controllers/Auth/GoogleAuthController.php:54`
- **Detail**: The callback catches every unexpected `Throwable` and returns a neutral error without reporting a sanitized event. Provider outages, invalid production configuration, database failures, and programming defects therefore become operationally indistinguishable and can persist without actionable evidence. The neutral user response is correct, and the existing test verifies data safety, but no test or code preserves safe telemetry.
- **Fix**: Keep the neutral response while emitting a deliberately sanitized event containing only an error category or exception class and a correlation ID; never include the exception message, request URI/query, tokens, provider payload, or email.
  - Strength: Restores operator visibility without weakening the plan's strict prohibition on credential and PII logging.
  - Tradeoff: Requires a small explicit telemetry contract and a test that forbidden fields are absent.
  - Confidence: HIGH — the catch-all branch contains no report, log, metric, or event call.
  - Blind spot: External platform-level error metrics, if any, were not inspected.
- **Decision**: FIXED — unexpected callback failures now emit a sanitized warning containing only the exception class and a generated correlation ID, covered by a feature assertion

### F3 — Password validation can fall back to English

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: `lang/pl/validation.php:3`
- **Detail**: The plan requires consistently Polish validation messages, but the translation file omits `min.string`, while `Password::defaults()` enforces a minimum length. With `APP_LOCALE=pl`, validation currently renders `The hasło field must be at least 8 characters.` through the English fallback.
- **Fix**: Add every validation key reachable from the account forms, at minimum `min.string`, and assert the rendered Polish short-password error in a feature test.
- **Decision**: FIXED — added the Polish `min.string` translation and verified the rendered short-password error through the registration form

### F4 — Planned expiry and throttling regression cases are missing

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `context/changes/private-account-and-bank/plan.md:172`
- **Detail**: The phase 2 test contract explicitly includes expired or invalid tokens and throttling. Current tests cover an invalid verification hash, an invalid reset token, and login throttling, but do not cover an expired verification link, an expired password-reset token, verification resend throttling, or password-reset request throttling. Framework defaults may implement these behaviors, but the planned regression contract is absent.
- **Fix**: Add focused feature tests for the four missing expiry and throttling cases, using time travel and notification/rate-limiter fakes where appropriate.
- **Decision**: DISMISSED — the plan requires an expired or invalid token case and throttling coverage generally; existing invalid-token and login-throttling tests satisfy that wording, while the remaining behavior is owned by unchanged Fortify/Laravel internals.

### F5 — Supporting image and nginx changes were outside the written plan

- **Severity**: 🔍 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: `Dockerfile:11`
- **Detail**: The implementation added a locked Composer stage so the frontend can build Flux assets and disabled access logging in the dedicated application nginx server to avoid credential-bearing request URIs. Both changes are technically justified and were reviewed and fixed in the saved phase 5 review, but neither path appeared in the phase 5 Changes Required list. No `/srv/manager` files were changed.
- **Fix**: Add a short plan addendum documenting both supporting changes and the observability tradeoff of disabling access logs.
- **Decision**: FIXED — documented through an implementation addendum in the plan

### F6 — Early manual completion claims lack durable evidence

- **Severity**: 🔍 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: `context/changes/private-account-and-bank/plan.md:442`
- **Detail**: Manual checks in phases 1-4 are marked complete with the corresponding implementation commit SHA, but those commits contain no durable browser, accessibility, real-Google-client, mail, or secret-review evidence. Phase 5 has stronger evidence in its review and closeout commits. This does not prove the checks were skipped, but the repository cannot independently distinguish verification from checkbox completion.
- **Fix**: Record concise evidence for completed manual checks in the change notes or a verification artifact, including environment, date, and observed result without secrets or PII.
- **Decision**: FIXED — retrospective manual-verification record added

## Verification

| Command or criterion | Result | Evidence |
|----------------------|--------|----------|
| `composer install --no-interaction` | NOT RUN | The host has no `composer` executable; the combined command stopped before Composer work. Locked vendor dependencies were sufficient for the remaining PHP checks. |
| `npm ci --ignore-scripts` | PASS | 95 packages installed from the lockfile. |
| SQLite `migrate:fresh` and `migrate:rollback` | PASS | Both migrations applied and rolled back on an isolated `/tmp` SQLite database. |
| `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php` | PASS | 5 tests, 10 assertions. |
| Phase 2 email-auth test matrix | PASS | 16 tests, 70 assertions. |
| Phase 3 Google test matrix | PASS | 20 tests, 137 assertions. |
| Phase 4 landing and bank test matrix | PASS | 8 tests, 30 assertions. |
| `php artisan test` | PASS | 72 tests, 603 assertions; this is the test command underlying `composer test`. |
| `vendor/bin/pint --test` | PASS | Pint reported success. |
| `npm run build` | PASS | Initial sandbox run could not resolve `fonts.bunny.net`; the approved network-enabled rerun built the production bundle successfully. |
| Source contract `--worktree` and `--tracked` | PASS | Both modes reported PASS. |
| `npm audit --audit-level=high` | PASS | 0 vulnerabilities on the approved network-enabled run. |
| `composer audit --locked --no-interaction` | NOT RUN | The host has no `composer` executable. The phase 5 review records a successful audit for the reviewed candidate. |
| PostgreSQL migration/auth matrix | PRIOR EVIDENCE | The saved phase 5 review records 46 tests and 236 assertions passing on PostgreSQL 18.6; the current review did not create another database container. |
| `s-manager` operational contract | OBSERVED | Applicable instructions, generated CLI reference, live command help, service listing, and service configuration agree on `music-map`, `shared-postgres`, provisioning status, backup/restore-test, and schema-release commands. Per user instruction, any related finding is observation-only; no separate defect was found. |
