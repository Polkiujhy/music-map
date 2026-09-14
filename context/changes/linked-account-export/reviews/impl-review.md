<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Eksport na powiązane i zarządzane konto

- **Plan**: context/changes/linked-account-export/plan.md
- **Scope**: Automated implementation, Phases 1–5 of 5; manual acceptance reviewed as pending
- **Date**: 2026-09-14
- **Verdict**: NEEDS ATTENTION — post-triage; manual acceptance remains pending
- **Findings**: 2 critical, 7 warnings, 0 observations (initial review)
- **Triage**: COMPLETE — 7 fixed, 1 fixed differently, 1 accepted

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — Partial replacement retries can no longer converge

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Actions/Exports/RunExportOperation.php:130
- **Detail**: Existing targets are inspected with the previously persisted local name and description (`RunExportOperation.php:130-131,207-215`). Both writers update provider metadata before replacing items (`SpotifyPlaylistWriter.php:210-224`; `YouTubePlaylistWriter.php:252-267`), while the local target metadata is updated only after complete success (`PersistExportTarget.php:129-156`). If metadata succeeds and item replacement fails, the retry compares the provider's new metadata with the old local snapshot and returns `invalid-response` (`SpotifyPlaylistWriter.php:250-255`; `YouTubePlaylistWriter.php:76-81`). Ordinary manual description drift is rejected for the same reason instead of being overwritten. This violates the central exact-replacement and partial-retry convergence contract.
- **Fix**: Make persisted-target inspection validate stable identity, ownership, existence, and visibility without requiring mutable metadata to equal the prior local snapshot; then add Spotify and YouTube regressions for metadata-success/item-failure retry and manual metadata drift.
  - Strength: Restores convergence and the explicit rule that a fresh confirmation replaces manual target changes.
  - Tradeoff: Requires a small contract adjustment in both writers and orchestration tests.
  - Confidence: HIGH — the state sequence and strict comparisons are directly visible in the implementation.
  - Blind spot: Real-provider behavior still requires the pending smoke tests.
- **Decision**: FIXED — persisted-target inspection now ignores mutable metadata; Spotify and YouTube regression coverage proves retry convergence after manual drift and a partial replacement.

### F2 — Phases 3–5 bypassed the formal Phase 2 stop gate

- **Severity**: ❌ CRITICAL
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Plan Adherence
- **Location**: context/changes/linked-account-export/plan.md:1022
- **Detail**: The plan forbids starting Phase 3 until real marker behavior, YouTube duplicate behavior, the managed-account 1000-playlist bound, and the exact published PaaS capability/version are documented (`plan.md:20-22,484-494`). Progress rows 2.5, 2.6, 2.8, and 2.10 remain unchecked, but Phases 3–5 were implemented and marked complete (`plan.md:1031-1064`). `music-map.managed-export.v1` is documented in README, so capability code exists; the required approval and external evidence do not.
- **Fix A ⭐ Recommended**: Hold release, perform the four Phase 2 gates, record their evidence and `music-map.managed-export.v1` explicitly in the plan, and add an addendum acknowledging the implementation-order deviation.
  - Strength: Validates the external assumptions before production without discarding completed internal work.
  - Tradeoff: May expose provider constraints that require implementation changes.
  - Confidence: HIGH — Progress and the phase-stop language are unambiguous.
  - Blind spot: This review cannot access the real provider accounts or approve the external capability.
- **Fix B**: Disable or revert the Phase 3–5 delivery path until the gates are completed, then reintroduce it.
  - Strength: Restores strict sequencing and prevents unvalidated behavior from shipping.
  - Tradeoff: Larger delivery disruption and duplicated integration effort.
  - Confidence: MED — whether disabling is necessary depends on current deployment state.
  - Blind spot: Deployment/feature-flag state was not inspected.
- **Decision**: ACCEPTED — user will complete the manual gates at final acceptance; some require the Manager environment.

### F3 — YouTube item-level 404 can retire a healthy playlist

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/PlaylistExport/Providers/YouTubePlaylistWriter.php:497
- **Detail**: Every failed YouTube mutation is classified with `targetRequest=true`, and every HTTP 404 then becomes `target-deleted` (`YouTubePlaylistWriter.php:497-512,569-585`). A `playlistItems.insert`, update, or delete can return 404 for an item/video occurrence rather than for the playlist. The orchestrator then retires the healthy link and clears its active key (`RunExportOperation.php:184-188`), permitting a later review to create a duplicate playlist.
- **Fix**: Parse the YouTube error reason and return `target-deleted` only for definitive target-playlist absence; classify item/video 404s as incomplete/invalid and add reason-specific tests.
  - Strength: Preserves the durable target identity and prevents duplicate creation after an item-level failure.
  - Tradeoff: Adds provider-specific error mapping and fixtures.
  - Confidence: HIGH — all mutation 404s currently share the same unconditional branch.
  - Blind spot: The exact reason vocabulary should be confirmed against live/API fixtures.
- **Decision**: FIXED — YouTube 404 classification now retires a target only for `playlistNotFound`; regression tests preserve the link for `videoNotFound` and `playlistItemNotFound`.

### F4 — Unsupported duplicates leave the user in a dead-end retry loop

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: app/Actions/Exports/RetryExportOperation.php:22
- **Detail**: YouTube correctly fails unsupported duplicates before mutation (`YouTubePlaylistWriter.php:193-203`), but failure persistence leaves the operation `active_key` set. Both the retry action and panel allow retry for every failure except `target-deleted` and `recovery-abandoned` (`RetryExportOperation.php:22-29`; `ExportOperationPanel.php:132-142`). Retrying the unchanged frozen manifest can only fail again, while the active key blocks the new review required by the plan.
- **Fix**: Treat `unsupported-duplicate` as terminal-requires-new-review: clear its operation active key, hide/reject retry, expose the new-review path, and test the complete transition.
  - Strength: Gives the user the planned correction path and prevents futile retries.
  - Tradeoff: Requires coordinated state, action, panel, and test changes.
  - Confidence: HIGH — the current allow/deny lists and retained active key are explicit.
  - Blind spot: Copy for correcting the source may need product review.
- **Decision**: FIXED — `unsupported-duplicate` releases the operation active key, cannot be retried, offers a new review, and preserves any existing target link.

### F5 — PostgreSQL acceptance is checked off but all tests skip

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: context/changes/linked-account-export/plan.md:1061
- **Detail**: The Phase 5 row claims the PostgreSQL operation and admission tests pass. The prescribed command exited 0 during this review but reported 6 tests, 0 passed, 6 skipped because the active database was not PostgreSQL. The concurrency tests are present and correctly guard their environment, but there is no verified disposable-PostgreSQL execution evidence.
- **Fix**: Run the two suites against a disposable PostgreSQL database with `pcntl`, require six actual passes, and attach the command output/evidence before retaining the completed 5.1 status.
  - Strength: Proves the exact lock/unique/race behavior that SQLite cannot establish.
  - Tradeoff: Requires a configured disposable PostgreSQL environment.
  - Confidence: HIGH — the fresh command output explicitly reports every test skipped.
  - Blind spot: A prior external run may exist but is not referenced by the plan or repository.
- **Decision**: FIXED DIFFERENTLY — Progress 5.1 is now a pending post-deployment manual check on an isolated disposable PostgreSQL database; it must never run against production data.

### F6 — Declared automated regression coverage is narrower than the plan

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: tests/Feature/Exports/PlaylistExportTargetBoundaryTest.php:21
- **Detail**: The source/target boundary test covers bank hiding, direct item editing, and review start only; it omits the planned direct proofs for reimport/import takeover, metadata refresh/reconcile, preparation job, refresh job, and maintenance command. Start-operation tests omit explicit wrong-owner and non-confirmed-review cases. Phase 4 tests omit the promised double-click/double-submit and page-refresh scenarios. Production guards mostly exist, but the completed Progress rows overstate the specified regression proof.
- **Fix**: Add the omitted direct boundary, start authorization/state, double-submit, and reload regression cases, then map each completed Progress claim to a passing test.
  - Strength: Protects the cross-entry-point invariant and UI idempotency the plan explicitly prioritized.
  - Tradeoff: Multiple focused test additions across three suites.
  - Confidence: HIGH — the named test files and methods do not contain these cases.
  - Blind spot: Some behavior may be incidentally covered in older suites, but not as the plan's direct boundary proof.
- **Decision**: FIXED — direct target-boundary, owner/status, double-retry, and terminal-reload regression coverage was added and passes.

### F7 — Bank loads unbounded export history

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/BankController.php:20
- **Detail**: The bank loads every source playlist and then every historical export operation for all displayed playlists into memory (`BankController.php:20-36`), although the view needs active unlinked operations and the latest operation for each link. Export history is append-only, so response time and memory grow without bound. This avoids literal N+1 queries but drifts from the planned latest-operation query.
- **Fix**: Paginate source playlists and constrain eager data to active unlinked operations plus the latest operation per export link using a relationship/subquery.
  - Strength: Keeps bank cost bounded while preserving the current presentation.
  - Tradeoff: Requires careful latest-per-group SQL/Eloquent handling across SQLite and PostgreSQL.
  - Confidence: HIGH — the current queries call unfiltered `get()` and attach the entire history.
  - Blind spot: Production history volume is unknown.
- **Decision**: FIXED — the bank now paginates source playlists and loads only active operations plus the latest operation per export link; regression tests cover historical exclusion and pagination.

### F8 — Managed access ignores token expiry during a 360-second operation budget

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/PlaylistExport/Actions/WithManagedExportAccess.php:24
- **Detail**: The public managed-access DTO includes `expiresAt`, but `WithManagedExportAccess` validates only provider and operation ID before invoking adapters (`WithManagedExportAccess.php:24-41`). Each writer permits up to 360 seconds of I/O. A valid but near-expiry token can therefore expire between mutations and create avoidable partial exports; no near-expiry test exists.
- **Fix A ⭐ Recommended**: Define a public minimum remaining-lifetime guarantee and reject credentials whose `expiresAt` does not cover the provider I/O budget plus a safety margin.
  - Strength: Small, fail-closed change that uses the existing DTO and avoids mid-operation expiry.
  - Tradeoff: Can reject otherwise usable short leases and requires the PaaS contract to guarantee sufficient lifetime.
  - Confidence: MED — the code gap is clear, but actual issued lifetimes were not observed.
  - Blind spot: The external capability may already guarantee a minimum lifetime not documented here.
- **Fix B**: Add a managed mutation guard that safely reacquires access before expiry and passes refreshed credentials to mutations.
  - Strength: Handles genuinely short-lived credentials without reserving a long lease.
  - Tradeoff: Broadens the writer/access contract and substantially increases retry/rotation complexity.
  - Confidence: MED — feasible, but it changes the current ephemeral-token interface.
  - Blind spot: Provider and broker support for mid-operation reacquisition was not verified.
- **Decision**: FIXED — managed access now requires at least 390 seconds of remaining token lifetime (360-second provider I/O budget plus a 30-second safety margin); shorter credentials fail retryably before the adapter or any provider mutation runs, with deterministic 389/390-second boundary tests.

### F9 — Unexpected worker exceptions lose causal diagnostics

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Pattern Consistency
- **Location**: app/Actions/Exports/RunExportOperation.php:49
- **Detail**: A blanket `catch (Throwable)` maps programming defects, database failures, and unexpected integration errors to `temporary-failure`, then a new generic `RuntimeException` drives retry (`RunExportOperation.php:49-69,264-272`). The original exception is neither reported nor preserved, so operational diagnosis is lost and users may be told the provider is temporarily unavailable for an application defect. Nearby import handling reports classified exceptions without logging secrets (`ImportPlaylist.php:154-165`).
- **Fix**: Catch known boundary failures explicitly; for unexpected throwables, emit a secret-safe report keyed by operation ID and exception class, and rethrow/preserve the original cause while keeping tokens and provider payloads out of logs.
  - Strength: Restores actionable diagnostics and matches the repository's established failure-reporting pattern.
  - Tradeoff: Requires a careful redaction boundary and tests for reported versus user-visible errors.
  - Confidence: HIGH — the broad catch currently discards the caught value entirely.
  - Blind spot: External error monitoring configuration was not inspected.
- **Decision**: FIXED — unexpected worker exceptions now emit a secret-safe diagnostic containing only the operation ID, exception class, and source location before retaining the existing temporary-failure classification and safe retry; a regression test proves that an exception message containing a token is not logged or propagated.

## Automated Verification

- PASS — Phase 1 migration/model test: 1 test, 4 assertions.
- PASS — Phase 1 start/confirm tests: 8 tests, 33 assertions.
- PASS — Phase 1 queued dispatch recovery: 1 test, 3 assertions.
- PASS — Phase 1 target boundary: 1 test, 5 assertions.
- PASS — Spotify writer: 23 tests, 63 assertions.
- PASS — YouTube writer: 22 tests, 51 assertions.
- PASS — Phase 3 execution: 8 tests, 64 assertions.
- PASS — YouTube admission: 4 tests, 20 assertions.
- PASS — Phase 4 route/panel/bank: 20 tests, 119 assertions.
- PASS — Notification: 5 tests, 18 assertions.
- PASS — post-fix full `composer test`: 620 tests, 603 passed, 17 skipped, 3359 assertions.
- PASS — `vendor/bin/pint --test`.
- PASS — `npm run build`.
- PASS — source contract for `--worktree` and `--tracked`.
- NOT PROVEN — PostgreSQL suites: 6 tests, 0 passed, 6 skipped.

## Manual Verification

All 14 manual Progress rows remain pending: 1.4; 2.5, 2.6, 2.8, 2.10; 3.5, 3.6; 4.5, 4.6; 5.1; and 5.5–5.8. No completed manual row lacked visible evidence because none is marked complete. Per the user's accepted risk, these gates will be completed at final acceptance; 5.1 is explicitly post-deployment and must use an isolated disposable PostgreSQL database, never production data.

## Scope Notes

The implementation substantially matches the planned schemas, models, provider adapters, access boundaries, admission, orchestration, UI, notifications, and documentation. Supporting files such as `ResolveExportDestination.php`, `ImportResult.php`, and `PlaylistFactory.php` are related implementation support rather than scope expansion. No S-08/S-09/S-10 synchronization, rematching, silent deduplication, admin recovery panel, Manager internals, or token persistence was found.
