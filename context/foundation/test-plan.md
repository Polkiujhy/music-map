# Test Plan

> Phased test rollout for this project. Strategy is frozen at the top
> (§1–§5); cookbook patterns at the bottom (§6) fill in as phases ship.
> Read before writing any new test.
>
> Refresh: re-run `/10x-test-plan --refresh` when stale (see §8).
>
> Last updated: 2026-09-14

## 1. Strategy

Tests follow three non-negotiable principles for this project:

1. **Cost × signal.** The cheapest test that gives a real signal for the
   risk wins. Do not promote to e2e because e2e "feels safer." Do not put a
   vision model on top of a deterministic visual diff that already catches
   the regression.
2. **User concerns are first-class evidence.** Risks anchored in "<the
   team is worried about X, and the failure would surface somewhere in
   <area>>" carry the same weight as PRD lines or hot-spot data.
3. **Risks are scenarios, not code locations.** This plan documents *what
   could fail* and *why we believe it's likely* — drawn from documents,
   interview, and codebase *signal* (churn, structure, test base). It does
   NOT claim to know which line owns the failure. That knowledge is
   produced by `/10x-research` during each rollout phase. If the plan and
   research disagree about where the failure lives, research is the
   ground truth.

Hot-spot scope used for likelihood weighting: `app/`, `routes/`,
`resources/`, `database/` (53 commits in the 30 days ending 2026-09-14).

## 2. Risk Map

Risks are ordered by impact × likelihood. Sources are evidence that raised
the risk, never claims about where a failure lives.

| # | Risk (failure scenario) | Impact | Likelihood | Source (evidence — not anchor) |
|---|---|---|---|---|
| 1 | A provider reports failure or times out after applying a write, and retry creates a duplicate or changes the wrong playlist. | High | High | interview Q1–Q4; PRD 91–96; roadmap S-06/S-07 |
| 2 | A partial export is presented as complete or loses the state required to finish safely. | High | High | interview Q1; PRD 38, 95–96; roadmap S-06/S-07 |
| 3 | The exported content or destination differs from the review the user consciously confirmed. | High | High | PRD 34–35, 48–50, 87–90; roadmap S-05; hot-spot dir `app/Integrations/ExportMatching/` (14 changes/30d) |
| 4 | An authenticated user reads or changes another user's playlist, export review, or streaming connection. | High | Medium | PRD 74–75, 131–133; roadmap S-01/S-03/S-05 |
| 5 | A YouTube write bypasses global admission, charges a retry again, or starts after the daily budget is exhausted. | High | Medium | PRD 119; roadmap F-02; archived F-02 plan |
| 6 | A credential leaks through storage, logs, or errors, or an unlinked account remains usable. | High | Medium | PRD 85–86, 116; roadmap S-04/F-01; hot-spot dir `app/Integrations/StreamingAccounts/` (23 changes/30d) |
| 7 | A provider timeout or read failure publishes a partial or falsely current bank snapshot. | Medium | High | interview Q3–Q4; PRD 78–80, 114; roadmap S-02/S-03; hot-spot dir `app/Integrations/PlaylistImport/` (19 changes/30d) |

### Risk Response Guidance

| Risk | What would prove protection | Must challenge | Context `/10x-research` must ground | Likely cheapest layer | Anti-pattern to avoid |
|---|---|---|---|---|---|
| #1 | When S-06/S-07 write execution exists, retry recognizes an earlier external effect and never creates a second resource; if an ambiguous create cannot be reconciled safely, it remains in an explicit recovery state instead of retrying creation blindly. Until then, retain this as an acceptance criterion rather than treating probe cleanup as export safety. | A failure response means no side effect occurred, or the provider resource ID is always available after an ambiguous create. | Stable operation identity; provider playlist ID persisted whenever known; provider-supported idempotency, deterministic discovery, or an explicit manual-recovery boundary for an applied create whose ID was not returned; provider semantics and reconciliation boundary. | deferred until writer exists; then DB-backed integration with an independent stateful HTTP fake, followed by a narrow provider smoke | A mock that always makes response and side effect agree, blindly recreating when the provider ID is unknown, or a probe test mislabeled as product-export safety. |
| #2 | When S-06/S-07 write execution exists, partial work remains explicit and resumable against the same provider playlist; final success follows observed exact contents, not only a successful write response. | A final success response proves every item was written. | Write ordering, nonfinal statuses, durable provider playlist identity, recovery/reconciliation contract, and final-content oracle. | deferred until writer exists; then integration at the external HTTP edge | Happy-path-only assertions or checking only local completion state and request counts. |
| #3 | The frozen manifest exactly matches the reviewed content, decisions, owner, and destination. | The rendered screen is a sufficient oracle. | Fingerprint, decision persistence, destination resolution, queue boundary. | feature + integration | Expected values copied from production calculations. |
| #4 | Every non-owner entry path denies access without disclosing resource data. | Authentication implies authorization. | Ownership rules and all HTTP/action entry points. | feature | Testing only the owner's path. |
| #5 | The admission primitive preserves one reservation per logical operation across limit, retry, reset, concurrency, and ambiguous commit; each future consumer persists and reuses that identity before any mutation. | Passing SQLite tests proves production atomicity, or binding the admission port proves future writers cannot bypass it. | Transaction boundary, Pacific reset clock, existing PostgreSQL race behavior, stable consumer operation identity, and fail-closed orchestration. | retain and run the existing PostgreSQL integration suite; add narrow consumer integration and one consumer race when the first writer lands | Duplicating an existing primitive race while leaving consumer bypass and unstable identity untested. |
| #6 | Secrets stay out of plaintext state, logs, and errors, and unlink removes usable access. | Database encryption covers every disclosure path. | Token lifecycle, logging, error translation, unlink semantics. | unit + feature | Checking only the database schema. |
| #7 | Failure publishes neither a partial snapshot nor a false freshness state. | An empty response means an empty playlist. | Transaction boundary, failure mapping, freshness source, external edge. | integration with fake HTTP | Mocking internal collaborators instead of the HTTP edge. |

Exact S-06/S-07 write execution is not implemented yet. Phases protect the
existing handoff, admission, persistence, and retry contracts now; provider
write scenarios become acceptance criteria when those roadmap slices land.

## 3. Phased Rollout

| # | Phase name | Goal (one line) | Risks covered | Test types | Status | Change folder |
|---|---|---|---|---|---|---|
| 1 | Ambiguous outcomes and safe retry | Prove atomicity, idempotency, and truthful states around timeouts and partial effects without testing nonexistent export code. | #1, #2, #5, #7 | contract + integration + PostgreSQL | complete | `testing-ambiguous-outcomes-safe-retry` |
| 2 | Decision integrity and access boundaries | Prove confirmed content, ownership, and credential confidentiality across application flows. | #3, #4, #6 | unit + feature + integration | not started | — |
| 3 | Provider realism and quality gates | Add the smallest live-provider smoke needed for ambiguous outcomes and lock deterministic regression gates. | #1–#7 cross-cutting | contract smoke + gates | not started | — |

Status vocabulary: `not started` → `change opened` → `researched` →
`planned` → `implementing` → `complete`.

## 4. Stack

Test-base profile: **meaningful** — PHPUnit is configured and 72 test files
are distributed across 44 Feature and 28 Unit tests.

| Layer | Tool | Version | Notes |
|---|---|---|---|
| unit + integration | PHPUnit via Laravel test runner | 12.5.34 | `tests/Unit` and `tests/Feature`; `composer test` is the full local command. |
| framework/HTTP | Laravel testing + HTTP fakes | 13.31.0 | Prefer Feature tests and fake only external HTTP boundaries. |
| production database behavior | PostgreSQL disposable test path | project contract | Required for concurrency and locking claims that SQLite cannot prove. |
| e2e | none yet | — | Add only through `/10x-e2e` when a browser-only risk survives cheaper layers. |
| accessibility/visual | none yet | — | No rollout phase is justified solely for this gap. |

**Stack grounding tools (current session):**
- Docs: no general docs MCP available — official Laravel 13 and PHPUnit 12.5 documentation checked via web; checked: 2026-09-14.
- Search: web search available — used only to locate current official framework/test-runner documentation; checked: 2026-09-14.
- Runtime/browser: no Playwright/browser automation tool available; Playwright is not configured locally; checked: 2026-09-14.
- Provider/platform: GitHub connector available — potentially useful for read-only PR/check inspection, not used; checked: 2026-09-14.

## 5. Quality Gates

| Gate | Where | Required? | Catches |
|---|---|---|---|
| `composer test` | local + CI | required | unit and application-flow regressions |
| `vendor/bin/pint --test` | local + CI | required | PHP formatting drift |
| `npm run build` | local + CI | required | Vite/Tailwind production build failures |
| source-contract verification | local + CI | required | release manifest drift |
| targeted PostgreSQL suite | local + CI | required after §3 Phase 1 | concurrency and locking regressions |
| provider contract smoke | controlled pre-production | required after §3 Phase 3 | documented response differing from observable provider effect |

## 6. Cookbook Patterns

How to add new tests in this project. Placeholders are replaced as rollout
phases ship.

### 6.1 Testing ambiguous provider outcomes and retry

- Model provider state independently from the response stream. A fake must be
  able to apply an effect and then lose its acknowledgement; scripted responses
  alone are not evidence of what the provider now contains.
- Before the first mutation, persist a stable logical operation identity and
  reuse it on every retry. A known provider destination must remain tied to that
  operation. If an ambiguous create returns no destination ID, use
  provider-supported idempotency, deterministic discovery, or an explicit
  manual-recovery state that forbids blind recreation.
- Resume partial work against the same destination by observing and reconciling
  provider state. Report final success only after an independent read-back
  exactly matches the confirmed ordered target, including duplicate
  occurrences. These are acceptance criteria for S-06/S-07 until their writers
  exist; probe cleanup is not proof of product-export retry safety.

### 6.2 Testing persistence and concurrency

- Use SQLite for deterministic transaction gating, rollback, failure mapping,
  and stored-state invariants. For imports, fake only provider HTTP, complete
  the entire read before persistence, and compare the saved parent, freshness,
  and ordered children before and after a failed atomic replacement.
- Use a disposable PostgreSQL database for `FOR UPDATE`, inter-process races,
  lock timeouts, SQLSTATE retry, Pacific reset timing under contention, and
  ambiguous commit acknowledgement. The existing
  `YouTubeWriteAdmissionPostgresTest` is the canonical primitive race package;
  do not duplicate it with a SQLite or second primitive race.
- When the first writer lands, add one consumer-level race proving that the
  same durable operation identity carries one admission into fail-closed,
  retry-safe orchestration. Binding the admission port alone does not prove a
  consumer cannot bypass it.

### 6.3 Testing authorization and confirmed decisions

- TBD — see §3 Phase 2 for owner/non-owner matrices and frozen-manifest assertions.

### 6.4 Testing a provider contract

- Fake only the external Laravel HTTP boundary; keep readers, actions,
  transactions, and persistence real. Assert semantic payload contracts, not
  merely HTTP status or request count: malformed HTTP 200 data is an invalid
  response, while an empty playlist succeeds only when metadata count, page
  total, and `items = []` consistently report zero.
- Use independently captured persisted state as the oracle for failed reads.
  Provider errors, transport failures, and incomplete payloads must not publish
  partial children or advance freshness.
- Keep the controlled live-provider contract smoke reserved for §3 Phase 3,
  after deterministic tests have covered every representable response and
  state transition.

### 6.5 Adding an e2e test

- TBD — browser tests are not currently justified. If a browser-only risk appears, use `/10x-e2e` and the repository locator, state-waiting, independence, and cleanup rules.

### 6.6 Per-rollout-phase notes

- Phase 1 delivered an explicit YouTube reader contract for malformed HTTP 200
  versus a consistent zero-item snapshot, plus a Feature test in
  `PlaylistImportTest` proving that transport loss after valid metadata leaves
  the complete previous snapshot and freshness unchanged.
- Phase 1 retained the existing PostgreSQL admission package as the canonical
  proof for limit, retry, reset, locking, races, SQLSTATE failures, and lost
  commit acknowledgement; no duplicate primitive race was added.
- Executable tests for risks #1 and #2 remain deferred until S-06/S-07 provide
  writers and durable export state. Those slices must cover ambiguous create
  through provider idempotency, deterministic discovery, or explicit
  manual-recovery; resume the same destination; and declare final success only
  after exact ordered provider read-back.
- Each rollout phase appends only reusable findings that changed how future tests should be written.

## 7. What We Deliberately Don't Test

No exclusions were agreed: Phase 2 interview Q5 was skipped. Apply cost ×
signal to every candidate and revisit this section during refresh.

## 8. Freshness Ledger

- Strategy (§1–§5) last reviewed: 2026-09-14
- Stack versions last verified: 2026-09-14
- AI-native tool references last verified: 2026-09-14

Refresh (`/10x-test-plan --refresh`) when:

- a new top-3 risk surfaces from the roadmap or archive,
- a recommended tool's `checked:` date is older than three months,
- the project's tech stack changes (new framework, new test runner),
- §7 negative-space no longer matches what the team believes.
