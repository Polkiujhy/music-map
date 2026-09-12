<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Prywatne konto i wejście do banku playlist

- **Plan**: `context/changes/private-account-and-bank/plan.md`
- **Scope**: Phase 5 of 5, pre-release candidate
- **Date**: 2026-09-12
- **Verdict**: APPROVED after fixes; production checks 5.8-5.10 remain pending
- **Findings**: 1 critical, 2 warnings, all fixed before publication

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS after fixes |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING — trusted CI and production steps pending |

## Findings

### F1 — Credential-bearing authentication URLs entered nginx access logs

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — the correction is narrow and explicit
- **Dimension**: Safety & Quality
- **Location**: `docker/nginx/default.conf`; `/srv/manager/services/public-edge/nginx/default.conf`
- **Detail**: Default nginx logging records the full request URI, including Google OAuth query parameters, verification signatures, and reset-password tokens. Both the public edge and application proxy were affected.
- **Fix**: Disable access logging for the dedicated Music Map server at both ingress layers while retaining error logs, and assert both configurations in contract tests.
- **Decision**: FIXED

### F2 — PostgreSQL CI service used a mutable tag

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — digest pinning is a local workflow change
- **Dimension**: Safety & Quality
- **Location**: `.github/workflows/ci.yml`
- **Detail**: `postgres:18.6-alpine` could move independently of the reviewed candidate.
- **Fix**: Pin PostgreSQL 18.6 Alpine to its exact SHA-256 digest and assert the digest form in the infrastructure contract test.
- **Decision**: FIXED

### F3 — Login-throttling test did not exercise the rejected request

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — one test needed the actual sixth request
- **Dimension**: Success Criteria
- **Location**: `tests/Feature/Auth/AuthenticationTest.php`
- **Detail**: Five failed attempts are allowed by the configured limiter, so asserting the fifth normal validation response did not prove throttling.
- **Fix**: Send five allowed failures and assert HTTP 429 on the sixth request.
- **Decision**: FIXED

## Verification

- Full PHPUnit suite after review fixes: 71 tests, 600 assertions passed.
- PostgreSQL 18.6 smoke: both migrations and 46 critical tests (236 assertions) passed.
- Laravel Pint, production frontend build, and worktree source contract passed.
- Composer and npm audits reported no known vulnerabilities.
- Public-edge `nginx -t` passed; the focused public-edge contract test passed.
- The full public-edge test file had 8 passes and one sandbox-only `systemd-tmpfiles` ownership failure unrelated to these changes.
- Hosted PR CI and production steps 5.8-5.10 must still pass before the release is complete.
