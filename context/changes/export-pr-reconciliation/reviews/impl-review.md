<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: integracja PR 59, 61 i 62

- **Plan**: context/changes/export-pr-reconciliation/plan.md
- **Scope**: Phases 1–4 of 5
- **Date**: 2026-10-01
- **Verdict**: APPROVED after remediation
- **Findings**: 1 critical, 3 warnings, 1 observation

## Review Context

- **Progress**: 8/25 checks complete; the current phase is Phase 5. Only fully completed Phases 1–4 and the review-remediation step 4.4 were reviewed.
- **Git scope**: `38d6db8..5a70b19`.
- **Exact candidate**: `5a70b1973dcb6958e0eb907e7c06b19878372f93`.

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Verification Evidence

| Check | Result |
|-------|--------|
| `composer test` | PASS — 778 tests: 757 passed, 21 PostgreSQL-only skipped; 4528 assertions |
| `vendor/bin/pint --test` | PASS |
| `npm run build` | PASS |
| `composer validate --strict --no-interaction` | PASS |
| `composer audit --locked --no-interaction` | PASS — no advisories |
| `npm audit --audit-level=high` | PASS — 0 vulnerabilities |
| `sh scripts/verify-source-contract --worktree` | PASS |
| `sh scripts/verify-source-contract --tracked` | PASS |
| `git diff --check` | PASS |
| GitHub PR #64 CI at exact HEAD | PASS — application, source-security, PostgreSQL 18 smoke, and container-images |

## Findings

### F1 — Spotify retry can reverse a completed Push into a destructive Pull

- **Severity**: ❌ CRITICAL
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Safety & Quality
- **Location**: app/Actions/PlaylistSync/RunPlaylistSynchronization.php:105
- **Detail**: A Push is pinned on retry only when `checkpoint` is an array. `SpotifySourcePlaylistWriter` performs its remote PUT at lines 56–57 but never records a checkpoint; if the PUT succeeds and the confirming read at lines 66–70 returns a retryable 429/5xx, the run remains `running` and the queue releases it for retry. The next attempt compares the now-changed remote source with the old baseline, selects Pull at `DeterminePlaylistSyncDirection.php:19-23`, and can silently overwrite a concurrent bank edit or remove unavailable Spotify items that intentionally exist only in the bank. YouTube avoids this class through a durable checkpoint, but no Spotify post-write-ambiguity regression exists. This violates the plan's retry identity, source-wins, bank-preservation, and partial-failure guarantees.
- **Fix**: Persist a Spotify write-started checkpoint before the PUT and make retries reconcile the current remote snapshot against both the frozen desired projection and the pre-write source before deciding success, retry, or `ExternalDrift`.
  - Strength: Extends the durable pattern already used by YouTube, preserves the original Push input, and prevents an ambiguous successful write from being mistaken for an external source edit.
  - Tradeoff: Requires coordinated writer/coordinator state-machine changes and regression coverage for PUT-success/read-failure, concurrent bank edits, and unavailable bank-only items.
  - Confidence: HIGH — the state transition and retry path are explicit in the coordinator, writer, direction resolver, and queue job.
  - Blind spot: No live Spotify fault injection was performed; the finding follows the deterministic retry path after a provider response failure.
- **Decision**: FIXED — a durable Spotify `write-started` checkpoint freezes the Push, and retry reconciliation distinguishes completed writes, safe retries, and external drift. Regression coverage preserves later bank edits and unavailable bank-only items.

### F2 — Recreated YouTube targets bypass the fresh-target grace period

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: app/Actions/ManagedAccountExport/RunManagedExport.php:204
- **Detail**: Fresh-target recovery requires `target_playlist_id === null`. `RequestManagedTargetRecreation.php:52-62` correctly keeps the prior local target row while abandoning the old attempt and creating a new generation. Therefore a newly recreated YouTube target can have a fresh current-generation locator while `target_playlist_id` is non-null; a transient `TargetMissing` skips the five-minute grace and immediately returns `RecreateRequired`. A second explicit recreation can then abandon a live fresh locator and create another remote copy. Existing fresh-target tests begin with a null target, while recreation tests do not exercise transient visibility after creating the new generation.
- **Fix**: Base the grace decision on the fresh resolved locator in the current target generation, independent of whether an older local target row remains bound, and add a recreated-target transient-404 regression.
  - Strength: Matches the plan's generation-based retry contract and preserves the local target row needed for safe rematerialization.
  - Tradeoff: The predicate must remain strict about provider, generation, resolved locator, and bounded age so a genuinely deleted established target still requires explicit recreation.
  - Confidence: HIGH — both predicates and the missing test case are directly visible in the implementation.
  - Blind spot: The provider's eventual-consistency duration is not independently measured; the existing five-minute policy is retained.
- **Decision**: FIXED — the grace period now follows the fresh locator in the current generation even while the prior local target remains bound; recreated-target transient-missing coverage was added.

### F3 — Phase 4's durable evidence ledger does not describe the final candidate

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/changes/export-pr-reconciliation/implementation.md:77
- **Detail**: The ledger labels results for the earlier integration as final and Progress rows 4.1–4.3 cite only `97e1086`/`e05bb5b`, but later commits `a43b363`, `13e8809`, and `5a70b19` changed Phase 2–4 behavior and tests. This review independently verified exact HEAD locally and through green PR #64 CI, so the executable success criterion passes; the durable plan/implementation evidence remains stale and the change notes also do not record the now-merged PR #64 state.
- **Fix**: Append an exact-candidate verification addendum, add a new immutable Progress step (for example 4.4) citing `5a70b19` and its CI evidence, and record PR #64's merged state without rewriting historical rows.
- **Decision**: FIXED — an exact-worktree remediation addendum and immutable Progress step 4.4 now record the final review candidate and gates. A closing commit SHA remains intentionally absent until these local changes are committed.

### F4 — UI copy promises exactly one completion email

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: resources/views/livewire/export-review-panel.blade.php:44
- **Detail**: The long-operation message promises “wyślemy jedną wiadomość e-mail,” while the plan explicitly excludes an exactly-once promise and documents at-least-once delivery with a possible send-before-checkpoint duplicate. The UI exposes a stronger guarantee than the implementation contract.
- **Fix**: Change the sentence to promise a completion notification without promising exactly one delivery, and update the matching UI assertion if present.
- **Decision**: FIXED — the UI promises an e-mail notification without claiming exactly-once delivery.

### F5 — Final materialization errors lose diagnostic evidence

- **Severity**: 👁️ OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Actions/ManagedAccountExport/MaterializeManagedExportPlaylist.php:149
- **Detail**: The final database materialization catches every `Throwable` and returns `false`, collapsing expected compare-and-set loss, database failures, and programming defects into `PersistenceFailure` without safe telemetry. Neighboring queue paths emit bounded structured events, so production diagnosis can distinguish much less here even though no direct correctness or disclosure flaw was demonstrated.
- **Fix**: Emit a safe structured warning containing only operation ID, generation, and exception class (no message, token, locator, or provider payload), and narrow caught exceptions where expected failure types are known.
- **Decision**: FIXED — materialization failures now emit bounded structured telemetry containing only operation ID, generation, and exception class, with regression coverage against message disclosure.

## Remediation Verification — 2026-09-15

All findings F1–F5 were fixed together in the local worktree based on exact HEAD
`5a70b1973dcb6958e0eb907e7c06b19878372f93`; no commit, push, deployment, live
provider write, or PaaS mutation was performed.

| Check | Result |
|-------|--------|
| Targeted regressions | PASS — 28 tests, 139 assertions |
| Full SQLite suite | PASS — 781 tests: 760 passed, 21 PostgreSQL-only skipped; 4546 assertions |
| Full PostgreSQL 18.6 suite | PASS — zero failures; 4720 assertions, including PostgreSQL-only lock/race coverage |
| `vendor/bin/pint --test` | PASS |
| `npm run build` | PASS |
| `sh scripts/verify-source-contract --worktree` | PASS |
| `git diff --check` | PASS |

The PostgreSQL verification used an isolated disposable database and a writable
temporary clone so source-contract self-tests could create their probes. PHPUnit
reported non-failing warnings/notices in that container; no test failed. The
database container and temporary clone were removed afterward.

## Publication review — 2026-10-01

Independent plan and safety reviewers confirmed that the existing F1–F5 remediation matches the plan and introduces no demonstrated new blocker. This reviews the uncommitted remediation based on `5a70b1973dcb6958e0eb907e7c06b19878372f93`; the publication commit supplies its durable Git identity.

Fresh local verification: SQLite PHPUnit PASS, 781 tests (760 passed, 21 PostgreSQL-only skipped), 4546 assertions; Pint PASS; Vite build PASS; source contract `--worktree` PASS. The earlier PostgreSQL result above remains historical evidence and was not rerun today.

S-11 and its three linked source changes remain open: 17 manual controls and the common acceptance/archive gate remain pending. Product publication does not complete live-provider acceptance. Progress rows and their historical suffixes are preserved.
