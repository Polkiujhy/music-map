<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Kontrola dopasowania przed eksportem

- **Plan**: context/changes/export-match-review/plan.md
- **Scope**: Phases 1–4 of 4 (automated implementation; manual gates pending)
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Post-triage status**: NEEDS ATTENTION — code findings resolved; planned manual acceptance and hosted PostgreSQL evidence remain intentionally pending
- **Findings**: 1 critical, 7 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | FAIL |
| Architecture | WARNING |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — A late decision can mutate a confirmed manifest

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Livewire/ExportReviewPanel.php:67
- **Detail**: `choose()` reads the review as `ready` without a transaction or lock, then separately loads and updates the item at lines 78–86. A concurrent confirmation can lock and commit the review as `confirmed` between those operations; the pending `choose()` request can then overwrite an item decision after confirmation. `ConfirmedExportManifest::fromConfirmedReview()` reconstructs the manifest from the current item decisions, so the supposedly frozen manifest can change after the user's confirmation. Existing race tests cover double confirmation and a late job, but not choose-versus-confirm.
- **Fix**: Perform `choose()` in a transaction that locks the review first, rechecks `ready`, then locks the owner-scoped item before updating it; add a deterministic PostgreSQL concurrency regression test.
  - Strength: Uses the same lock order as confirmation and restores the immutable-handoff guarantee at the write boundary.
  - Tradeoff: Requires a database-specific concurrency test in addition to the ordinary Livewire test.
  - Confidence: HIGH — the current read/check/write sequence has no database guard preventing a post-confirmation update.
  - Blind spot: SQLite cannot faithfully prove row-lock scheduling, so the concurrency case must run in the PostgreSQL job.
- **Decision**: FIXED — decision writes now lock the review before rechecking `ready`; PostgreSQL concurrency regression added

### F2 — Matching work can exceed the queue lease and overlap

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Architecture
- **Location**: app/Jobs/PrepareExportReview.php:22
- **Detail**: The job performs up to 20 sequential searches. Spotify can make 21 HTTP calls and YouTube up to 40, each with a 10-second timeout. The application queue worker uses `--timeout=90`, while the database connection also defaults `retry_after` to 90 seconds. A valid but slow run can therefore be terminated or redelivered while the first execution is still active. Atomic publication protects database state, but duplicate executions can repeat provider traffic and consume real YouTube quota outside the deduplicated accounting.
- **Fix A ⭐ Recommended**: Bound total job duration with limited concurrency or batching, add a per-review overlap lock, and define an application-visible queue timeout/lease contract with `retry_after` safely greater than the worker timeout.
  - Strength: Preserves the single-review orchestration while making its worst-case runtime compatible with delivery semantics and quota accounting.
  - Tradeoff: Adds concurrency and runtime-contract tests and requires selecting explicit safe timing margins.
  - Confidence: HIGH — the configured 90-second boundary is below the clients' allowed sequential worst case and equals the retry lease.
  - Blind spot: Production provider latency distribution is not available.
- **Fix B**: Fan out bounded item-match jobs and aggregate only after every result succeeds.
  - Strength: Gives each network unit a short lease and isolates retries to failed items.
  - Tradeoff: Substantially expands state, orchestration, atomic-publication, and failure-recovery complexity.
  - Confidence: MEDIUM — it removes the long monolithic job but is a broader architecture change than S-05 planned.
  - Blind spot: The desired queue throughput and worker concurrency are not yet specified.
- **Decision**: FIXED via Fix A — 450-second job/worker timeout, 510-second database retry lease, and per-review overlap lock

### F3 — Completion email does not return to the review

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: app/Notifications/ExportReviewCompleted.php:38
- **Detail**: The notification links to `bank.index?review={id}`, but `BankController` and the bank view ignore that query parameter. The plan requires an authenticated owner-scoped link back to the result, so the user lands only on the general bank and must find the review manually.
- **Fix**: Include the playlist ID in the queued notification and generate the owner-protected `export-reviews.show` route directly.
- **Decision**: FIXED — notification now links directly to the owner-protected playlist review route

### F4 — Livewire retry bypasses the retry throttle

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: resources/views/livewire/export-review-panel.blade.php:47
- **Detail**: The POST retry route has the planned per-user/provider throttle, but the rendered retry button calls the public Livewire `retry()` method directly. That method resolves and starts a replacement without applying the route limiter, while route tests only prove throttling for the unused HTTP path.
- **Fix**: Submit the retry button through the existing CSRF-protected, throttled POST route (or apply the identical limiter inside the Livewire action and test that path).
- **Decision**: FIXED — retry UI now submits to the CSRF-protected, throttled POST route; unthrottled Livewire action removed

### F5 — Cache identity omits planned normalization and owner scope

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: app/Jobs/PrepareExportReview.php:198
- **Detail**: `SourceTrack::fingerprint()` hashes raw source strings, while the cache key contains only provider, market, and that raw hash. The plan requires normalized fingerprint deduplication and explicitly prohibits mixing target owners. Equivalent case/whitespace variants therefore consume separate cache/budget entries, while linked and managed owners can share the same cached result.
- **Fix**: Build the cache fingerprint from the same normalized matching fields and include a non-secret destination-owner discriminator in the hashed cache-key material.
  - Strength: Implements the documented isolation rule and reduces duplicate YouTube budget consumption for semantically identical inputs.
  - Tradeoff: Invalidates existing ephemeral cache entries and reduces cross-owner cache reuse.
  - Confidence: HIGH — both omitted dimensions are directly visible in the current key construction.
  - Blind spot: Public catalog results are generally owner-independent; the owner-isolation requirement may be intentionally relaxed only by amending the plan.
- **Decision**: FIXED — cache fingerprint now uses versioned matching normalization and keys include destination type plus hashed target owner; S-03 canonical playlist-fingerprint integration recorded as a follow-up

### F6 — Notification is marked sent before enqueue succeeds

- **Severity**: ⚠️ WARNING
- **Impact**: 🔬 HIGH — architectural stakes; think carefully before deciding
- **Dimension**: Safety & Quality
- **Location**: app/Jobs/PrepareExportReview.php:184
- **Detail**: `notification_sent_at` is atomically claimed before `notify()` queues the mail. If notification dispatch throws, the review is already terminal and marked sent; a retry exits before notification handling and the email is permanently lost. The current test uses `Notification::fake()` and covers only successful dispatch.
- **Fix A ⭐ Recommended**: Use a durable outbox/notification job with an idempotency key, and mark delivery state through that recoverable workflow.
  - Strength: Separates terminal review publication from recoverable notification delivery and preserves at-most-once logical intent without silently losing enqueue failures.
  - Tradeoff: Adds a small durable-delivery component and operational states beyond the current boolean timestamp.
  - Confidence: HIGH — the marker-before-side-effect ordering demonstrably suppresses every later attempt after an exception.
  - Blind spot: Mail-provider acceptance still cannot guarantee inbox delivery.
- **Fix B**: On dispatch failure, conditionally clear the claim and rethrow so a later attempt can enqueue again.
  - Strength: Narrower change with no new table or domain object.
  - Tradeoff: A failure after partial queue acceptance can produce a duplicate, so the guarantee is weaker than an outbox.
  - Confidence: MEDIUM — it closes the obvious lost-enqueue path but cannot make the external boundary atomic.
  - Blind spot: The queue driver's exact partial-failure behavior has not been fault-injected.
- **Decision**: FIXED via Fix A — durable unique notification job retries failed delivery and records `notification_sent_at` only after success

### F7 — Bank loads unbounded review history

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/BankController.php:13
- **Detail**: The new eager load retrieves every queued, processing, ready, failed, and expired review for every playlist. Retries continually add failed/expired rows, while the view uses only the first active and first retryable review for each provider/destination. This avoids N+1 queries but creates unbounded database transfer and model hydration as history grows.
- **Fix**: Query only the newest active and newest retryable candidate needed per playlist/provider/destination, and define retention separately if historical failed reviews have no product use.
  - Strength: Bounds bank-request cost to the data the UI actually renders without weakening owner scoping.
  - Tradeoff: Requires a latest-per-group query or explicit derived relationships that behave consistently on SQLite and PostgreSQL.
  - Confidence: HIGH — the relation has no limit and the template discards all but a small fixed subset.
  - Blind spot: No production review-cardinality measurements exist yet.
- **Decision**: FIXED — bank now loads at most the latest active and retryable review per provider/current target and preserves the S-03 playlist-list contract

### F8 — Manual acceptance and live PostgreSQL evidence remain pending

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: context/changes/export-match-review/plan.md:616
- **Detail**: All 11 manual Progress items remain unchecked, covering schema/config review, classifier fixtures, email copy, responsive/accessibility UX, navigation/two-tab behavior, fake E2E, secret inspection, and release readiness. Local tests prove the PostgreSQL CI job definition, but this branch has no upstream and no observable hosted CI run at the reviewed HEAD, so the checked 4.7 claim is not backed by execution evidence available to this review.
- **Fix**: Execute and record the manual checklist against the exact candidate, push only with explicit owner approval, and attach a green hosted PostgreSQL `migrate:fresh` plus full-suite run before release acceptance.
  - Strength: Closes the plan's explicit human, provider-copy, accessibility, data-safety, and production-database risks with auditable evidence.
  - Tradeoff: Requires owner/operator participation and a hosted CI run; these cannot be inferred from local SQLite tests.
  - Confidence: HIGH — the canonical Progress section contains 11 unchecked items and the branch has no remote tracking ref.
  - Blind spot: A hosted run may exist under another ref or unpublished evidence not visible in this checkout.
- **Decision**: ACCEPTED — owner intentionally ran implementation review before manual acceptance and hosted CI to avoid redoing those checks after code fixes

### F9 — Local source state is reported as a catalog result

- **Severity**: 👁 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: app/Integrations/ExportMatching/Providers/SpotifyCatalogSearch.php:32
- **Detail**: Both catalog clients return `unavailable` without making a catalog request when the source snapshot is unavailable or titleless. The neutral-port contract says `unavailable` may be returned only after a valid catalog response with no credible candidate. The short circuit is operationally sensible, but it conflates a local source limitation with a verified target-catalog absence and the UI labels it as unavailable in the target.
- **Fix A ⭐ Recommended**: Amend the contract to define a local-source-unavailable short circuit and render copy that distinguishes it from a verified target-catalog miss.
  - Strength: Preserves quota and avoids a meaningless request while making the user-facing semantics truthful.
  - Tradeoff: The plan/domain language gains a documented exception or additional reason field.
  - Confidence: HIGH — the code path and contract wording directly conflict.
  - Blind spot: Product stakeholders have not chosen whether users need the reason distinction in MVP.
- **Fix B**: Introduce a typed local-input outcome distinct from `unavailable` and keep catalog `unavailable` exclusive to successful searches.
  - Strength: Maintains the strict catalog-result invariant throughout the domain.
  - Tradeoff: Expands enums, persistence, UI, tests, and the future export handoff.
  - Confidence: MEDIUM — semantically cleaner, but broader than the current three-class MVP.
  - Blind spot: Downstream S-06/S-07 handling for a fourth outcome is not designed.
- **Decision**: FIXED via Fix A — contract and UI now distinguish local source limitations from a verified target-catalog miss without making provider requests

## Verification Evidence

- `composer test` — PASS after triage, 421 tests total: 419 passed, 2 skipped, 2,532 assertions. Both skips are PostgreSQL-only concurrency checks under the local SQLite test environment.
- `vendor/bin/pint --test` — PASS.
- `npm run build` — PASS, Vite 8.2.2 production build.
- `npm run check:source` — PASS (`--worktree`).
- `composer audit --locked --no-interaction` — PASS, no advisories.
- `npm audit --audit-level=high` — PASS, 0 vulnerabilities.
- `php artisan test tests/Unit/ProductionInfrastructureTest.php` — PASS, 8 tests / 132 assertions; verifies the PostgreSQL CI job contains `migrate:fresh` and the full suite.
- Hosted PostgreSQL execution at reviewed HEAD — NOT OBSERVED; the local branch has no upstream tracking ref.

## Triage Summary

- **Fixed**: F1, F2 (Fix A), F3, F4, F5, F6 (Fix A), F7, F9 (Fix A) — 8 findings.
- **Accepted**: F8 — 1 finding. The owner intentionally scheduled manual acceptance and hosted CI after implementation-review fixes to avoid repeating those checks.
- **Skipped**: none.
- **Remaining release evidence**: complete the 11 manual Progress checks and record a green hosted PostgreSQL run against the final candidate.
