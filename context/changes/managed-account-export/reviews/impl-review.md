<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Eksport na konto techniczne music-map

- **Plan**: context/changes/managed-account-export/plan.md
- **Scope**: Phases 1–5 of 5 (implemented/automated work; 45/57 progress rows complete)
- **Date**: 2026-09-14
- **Verdict**: NEEDS ATTENTION
- **Findings**: 1 critical, 7 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — YouTube item deletion sends the required ID in the request body

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/ManagedAccountExport/Providers/YouTubeManagedPlaylistGateway.php:327
- **Detail**: `PendingRequest::delete($url, ['id' => $occurrenceId])` sends `id` using Laravel's configured body format. The YouTube `playlistItems.delete` contract requires `id` as a query parameter and explicitly forbids a request body. Any reconciliation that needs to remove an existing item can therefore fail against the live API. The fake test at `tests/Unit/Integrations/ManagedAccountExport/YouTubeManagedPlaylistGatewayTest.php:89` reads `$request->data()` and never verifies the query string, so it proves the invalid request shape. See https://developers.google.com/youtube/v3/docs/playlistItems/delete.
- **Fix**: Use `withQueryParameters(['id' => $occurrenceId])->delete(...)`, then assert the query parameter and empty body in the gateway test.
- **Decision**: FIXED — corrected the YouTube delete query shape and added URL/body assertions.

### F2 — Production access crossed an explicitly unresolved contract gate

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Plan Adherence
- **Location**: app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php:14
- **Detail**: The plan forbids beginning or completing production technical access until the exact published rotation-contract version and durable public source are recorded. The production adapter is implemented and bound, and progress 2.1 is checked, while `plan.md` still calls the contract pending and `reviews/manual-verification.md` leaves its version and compatibility as `PENDING`.
- **Fix A ⭐ Recommended**: If `music-map.managed-export.v1` is published, record its durable public source, accepted version, and consumer-visible semantics in the plan and complete the compatibility evidence.
  - Strength: Reconciles the existing implementation with its declared external dependency without importing Manager internals.
  - Tradeoff: Requires authoritative external confirmation before release.
  - Confidence: HIGH — the contradiction is explicit in the plan and release artifact.
  - Blind spot: Manager internals were intentionally not inspected because they are outside this repository boundary.
- **Fix B**: Disable or defer the production binding while retaining fake-backed contracts and provider gateways.
  - Strength: Restores the plan's fail-closed gate if the contract is not actually published.
  - Tradeoff: Managed export cannot run in production until the dependency is resolved.
  - Confidence: HIGH — this is the fallback required by the plan.
  - Blind spot: Deployment state was not inspected.
- **Decision**: FIXED via Fix A — recorded `music-map.managed-export.v1`, its published `s-manager-use` reference, and static compatibility evidence; live/operator confirmation remains pending.

### F3 — Managed-account identity has two independent configuration sources

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: app/Actions/ManagedAccountExport/RetryManagedExport.php:64
- **Detail**: Execution validates `services.managed_export.providers.*.account_id`, but destination resolution, retry/recreate guards, bank status, and review UI use `services.platform_access.*.technical.account_id`. `.env.example` exposes both independently. If they diverge, the app can offer and persist a target for account A while the worker fails closed because the managed-export boundary expects account B; retry visibility can also disagree with executable access.
- **Fix**: Establish one consumer-visible managed-export account identity source and use it for destination resolution, history guards, bank/status UI, and worker validation; add an integration test that proves legacy/new configuration divergence cannot occur silently.
  - Strength: Removes cross-layer disagreement and enforces the plan's no-probe product boundary.
  - Tradeoff: Touches the existing S-05 destination-resolution path and its tests.
  - Confidence: HIGH — both independent reads and environment keys are present in the implementation.
  - Blind spot: Runtime deployment may currently keep the values equal, but the repository does not enforce that invariant.
- **Decision**: FIXED — product destination, retry/recreate, bank, and review UI now share `services.managed_export.providers.*`; probe configuration remains isolated, with a divergence regression test.

### F4 — Item-level YouTube 404 is misclassified as a missing playlist

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/ManagedAccountExport/ManagedProviderFailureMapper.php:28
- **Detail**: The shared mapper converts every HTTP 404 to `TargetMissing` before considering the caller's `ItemRejected` fallback. A YouTube item insertion can return 404 for an unavailable video; `RunManagedExport` then moves the operation to `recreate_required` although the target playlist still exists. Recreating the playlist cannot repair the catalog item and needlessly changes the link.
- **Fix**: Make 404 mapping operation-aware: reserve `TargetMissing` for playlist inspection and honor an item-level `ItemRejected` result for insert/delete endpoints, with response-reason tests.
  - Strength: Keeps recovery actions aligned with the resource that actually failed.
  - Tradeoff: Requires endpoint-aware taxonomy instead of one status-only mapper.
  - Confidence: HIGH — the current mapper ignores its supplied item fallback for every 404.
  - Blind spot: Not every provider-specific 404 reason is represented in current fixtures.
- **Decision**: FIXED — mapper now accepts an endpoint-specific not-found code; YouTube item mutations return `ItemRejected`, covered by a `videoNotFound` regression test.

### F5 — Provider quota exhaustion consumes automatic retry claims

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/ManagedAccountExport/ManagedProviderFailureMapper.php:15
- **Detail**: Provider `quotaExceeded`/`dailyLimitExceeded` responses are marked retryable. The worker consequently retries with the generic 300-second delay and can consume all three durable claims, unlike F-02 admission refusal, which is terminal. A daily quota does not recover on that schedule, so the retries waste calls and obscure the intended quota-refusal state.
- **Fix**: Mark daily quota exhaustion non-retryable unless a trustworthy reset-time contract is available, and add a provider-response quota test.
- **Decision**: FIXED — provider daily quota responses are non-retryable, with gateway mapping and worker lifecycle regression coverage.

### F6 — Access-broker retry semantics are discarded at the adapter boundary

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Architecture
- **Location**: app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php:50
- **Detail**: `ManagedExportAccessException` carries validated `retryable` and `retryAfter` fields, but the adapter maps it to only a failure enum. `RunManagedExport` then invents retryability and delay from the enum. This can retry a non-retryable broker response or ignore its authoritative backoff.
- **Fix**: Preserve code, retryability, and bounded retry-after in the managed-access result and consume them in `RunManagedExport`; test the broker-to-adapter boundary.
  - Strength: Retains the public contract's error semantics end to end.
  - Tradeoff: Expands the access result DTO and several orchestration tests.
  - Confidence: HIGH — the exception fields are parsed but never read by the adapter.
  - Blind spot: Current production broker categories may happen to use the same defaults.
- **Decision**: FIXED — `ManagedAccessResult` preserves validated retryability and delay, and the worker consumes those values instead of inferring policy from the failure enum.

### F7 — Unexpected callback failures are silently converted into retryable transport errors

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/ManagedAccountExport/WithManagedAccountAccess.php:68
- **Detail**: The access wrapper catches every callback `Throwable` and returns `TransportUnavailable`. That includes programming errors and persistence exceptions after provider mutations; the worker then automatically replays them as network failures, and the original exception is neither logged nor exposed to normal job failure handling. Gateway transport boundaries already return typed provider failures.
- **Fix**: Translate only known boundary failures; let unexpected callback exceptions fail the job, or classify and log them using the durable mutation checkpoint rather than calling them retryable transport failures.
  - Strength: Preserves diagnostics and prevents incorrect automatic retry policy.
  - Tradeoff: Requires a deliberate exception contract for callback/application failures.
  - Confidence: HIGH — the blanket catch and retry mapping are direct code paths.
  - Blind spot: Exact-state reconciliation reduces duplicate risk, but it does not make misclassification or lost diagnostics harmless.
- **Decision**: FIXED — unexpected callback exceptions now propagate through normal job failure handling while the ephemeral access context is still cleared in `finally`.

### F8 — Release and human success criteria remain pending

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Success Criteria
- **Location**: context/changes/managed-account-export/reviews/manual-verification.md:1
- **Detail**: All 12 manual Progress rows remain unchecked. The release artifact still has `PENDING` for the exact candidate, both provider live smokes, secret-free operational review, cleanup, and the rotation-contract confirmation. The local automated suite is green, but PostgreSQL-specific tests skipped because no PostgreSQL test DSN was configured; the artifact records a prior disposable-PostgreSQL pass but does not replace the pending exact-candidate/live evidence.
- **Fix**: After code findings are resolved, run and record the controlled exact-candidate PostgreSQL and provider verification, then complete only the manual Progress rows supported by that evidence.
  - Strength: Satisfies the plan's explicit release gate and preserves secret-free evidence.
  - Tradeoff: Requires approved operational access and cannot be completed from this local review alone.
  - Confidence: HIGH — every outstanding item is explicitly marked pending.
  - Blind spot: External operational evidence may exist but is not referenced by the repository artifact.
- **Decision**: ACCEPTED — deferred to the end of the run; the user will complete the checks that require the authorized application and operational credentials.

### F9 — Recovery scheduler mutex can outlive the recovery leases

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: routes/console.php:15
- **Detail**: The every-minute recovery command uses `withoutOverlapping()` with its long default mutex expiry. If the scheduler process dies while holding the mutex, recovery can remain suppressed much longer than the 120-second publication and 510-second processing leases.
- **Fix**: Set an explicit overlap expiry aligned with the bounded recovery command and durable lease model.
- **Decision**: FIXED — recovery scheduler mutex now expires after 10 minutes, closely bounding it to the 510-second processing lease.

## Verification

| Command | Result |
|---------|--------|
| `php artisan test tests/Feature/ManagedAccountExport/ManagedExportMigrationTest.php` | PASS — 1 test, 18 assertions |
| `php artisan test tests/Feature/ManagedAccountExport/ManagedExportModelTest.php` | PASS — 4 tests, 49 assertions |
| `php artisan test tests/Feature/ManagedAccountExport/ManagedExportPostgresTest.php` | INCONCLUSIVE locally — 6 PostgreSQL tests skipped |
| Phase 1 regressions | PASS — 67 passed, 9 environment-dependent skips |
| Phase 2 access/marker/Spotify/YouTube gateway tests | PASS — 15 tests, 54 assertions |
| Phase 2 platform-access/Google regressions | PASS — 143 tests, 989 assertions |
| Phase 3 start/worker/materialization tests | PASS — 14 tests, 59 assertions |
| Phase 4 route/panel/bank/notification/recovery tests | PASS — 20 tests, 114 assertions |
| `php artisan test tests/Feature/ManagedAccountExport` | PASS — 63 passed, 6 PostgreSQL skips, 400 assertions |
| `composer test` | PASS — 576 passed, 17 environment-dependent skips, 3417 assertions |
| `vendor/bin/pint --test` | PASS |
| `npm run build` | PASS |
| `sh scripts/verify-source-contract --worktree` | PASS (with non-fatal concurrent stdout `printf` warnings) |
| `composer audit --locked --no-interaction` | PASS — no advisories |
| `npm audit --audit-level=high` | PASS — 0 vulnerabilities |

## Post-triage summary

- **Triage**: COMPLETE
- **Fixed**: F1, F2 (Fix A), F3, F4, F5, F6, F7, F9
- **Accepted/deferred**: F8 — manual exact-candidate and authorized live verification remains pending
- **Final automated verification**: PASS — 599 tests, 582 passed, 17 environment-dependent skips, 3442 assertions; Pint, frontend build, and source contract passed
- **Post-triage verdict**: NEEDS ATTENTION — code findings are resolved; release evidence remains incomplete until F8 is performed
