---
date: 2026-09-14T18:55:30+01:00
researcher: Codex
git_commit: e529135e2a7434e4519e4f8cb96bd9fc9c9a836c
branch: main
repository: music-map
topic: "Ground rollout Phase 1: ambiguous outcomes and safe retry"
tags: [research, codebase, playlist-import, export-retry, youtube-write-admission, postgresql]
status: complete
last_updated: 2026-09-14
last_updated_by: Codex
---

# Research: Ground rollout Phase 1 — ambiguous outcomes and safe retry

**Date**: 2026-09-14T18:55:30+01:00  
**Researcher**: Codex  
**Git Commit**: `e529135e2a7434e4519e4f8cb96bd9fc9c9a836c`  
**Branch**: `main`  
**Repository**: `music-map`

## Research Question

Ground risks #1, #2, #5, and #7 from rollout Phase 1 of
`context/foundation/test-plan.md` in current code. For each risk, identify the
real failure path, verify or correct the response guidance, locate existing
tests, choose the cheapest useful layer, and call out speculative risks or
misleading hot-spot evidence. In particular, do not infer that a failed
provider response means no external effect, that final success proves every
item was written, or that SQLite proves production locking.

## Summary

| Risk | Grounded verdict | Current protection and gap | Cheapest useful layer |
|---|---|---|---|
| #1 ambiguous provider write | **Prospective for product export.** S-06/S-07 have no writer, export job, provider destination persistence, or retry state. `PlatformAccess` mutates fixed probe fixtures; it is not the product export path. | Frozen confirmation, quota reservation reuse, and probe cleanup exist independently. Nothing composes them into one durable export operation or reconciles an applied-but-unacknowledged resource creation. | Now, only a probe-specific stateful HTTP-edge characterization is honest. When the writer exists, use a DB-backed integration test with an independent stateful provider fake; later add one narrow live-provider smoke. |
| #2 partial export | **Prospective for product export.** The application ends at “manifest ready for export”; it cannot yet publish false export completion because it cannot export. | The confirmed manifest freezes ordered target items and destination account, but there is no provider playlist ID, partial/nonterminal status, item progress, or recovery contract. | Defer the acceptance test to S-06/S-07. Then use a DB + external-HTTP-edge integration test that proves durable partial state, same destination on retry, and final observed contents—not merely a success response. |
| #5 YouTube admission | **Implemented primitive; prospective enforcement.** The reservation primitive covers limit, retry, reset, rollback, PostgreSQL locking, races, and lost commit acknowledgement. No production writer consumes it, so global non-bypass is not currently provable. | Existing PostgreSQL tests already contain deliberate distinct-key, same-key, midnight-lock, lock-timeout, SQLSTATE retry, and ambiguous-commit cases. The missing contract is future consumer orchestration and stable operation-ID ownership. | Retain and run the existing PostgreSQL feature suite; do not add a duplicate race. Add a narrow consumer integration test, plus one same-operation PostgreSQL consumer race, when a writer lands. |
| #7 failed provider read | **Implemented and substantially covered.** Readers fetch and validate a complete bounded snapshot before any persistence; replacement/reconciliation publishes inside a transaction. | Existing tests prove malformed reimport leaves the whole prior snapshot unchanged and rate/quota refresh failures do not advance freshness. The precise missing case is a transport failure between metadata and items at the application boundary, plus an explicit empty-response-vs-empty-playlist case. | Laravel feature/integration test with `Http::fakeSequence()` / failed connection at the real HTTP edge and SQLite persistence is sufficient; no PostgreSQL or browser is needed for this failure path. |

Phase 1 should therefore add or retain tests only where owning behavior exists.
Risks #1 and #2 become explicit S-06/S-07 acceptance criteria rather than tests
of probes or review confirmation mislabeled as export safety.

## Detailed Findings

### Risk #1 — ambiguous provider write and duplicate resource creation

#### Real failure path

There is no current product export write path. The HTTP surface stops at review
confirmation (`routes/web.php:80-89`), and confirmation reports only that the
manifest is ready (`app/Http/Controllers/PlaylistExportReviewController.php:73-84`):

```php
$confirm->handle(...);
// ...
->with('status', '... manifest jest gotowy do eksportu.');
```

The current durable pieces are disconnected:

- `export_reviews.correlation_id` is unique, but the table has no provider
  destination playlist ID or write/reconciliation state
  (`database/migrations/2026_09_14_000100_create_export_reviews_table.php:11-29`).
- `ConfirmedExportManifest` freezes review ID, source playlist ID, destination
  account, fingerprint, and ordered target IDs/URIs
  (`app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:18-27,35-61`).
- YouTube admission uses a different caller-supplied composite identity,
  `(operation_type, operation_id)`
  (`database/migrations/2026_09_14_000300_create_youtube_write_admission_tables.php:26-34`).
- `ManagedExportAccessBroker::acquire()` accepts an operation ID but returns
  ephemeral access; no exporter composes it with the manifest and reservation
  (`app/Integrations/ManagedExport/Contracts/ManagedExportAccessBroker.php:7-10`).

The only existing provider mutations are tester probes against a configured,
pre-existing fixture. YouTube inserts individual items, records acknowledged
item IDs, and marks 5xx/transport/missing-ID outcomes ambiguous before cleanup
(`app/Integrations/PlatformAccess/YouTubeProbe.php:227-280`):

```php
$insert = $request->post(...);
$ambiguousMutation = $insert->serverError();
// transport also sets $ambiguousMutation = true
$cleanupFailed = $this->cleanup(...);
```

Spotify replaces all fixture items, verifies the exact read-back, then restores
the fixture to empty (`app/Integrations/PlatformAccess/SpotifyProbe.php:194-231,234-269`).
Neither probe creates a playlist resource, persists a logical attempt, or
implements product retry.

#### Guidance verdict

The response intent is correct for future S-06/S-07: after timeout or 5xx, retry
must first reconcile by stable logical operation and persisted provider
destination identity, then reuse the resource rather than create another one.
There is one important qualification: an ambiguous *create* can take effect
without returning the provider playlist ID, so persisting that ID cannot by
itself protect the first retry. The writer needs a provider-supported
idempotency key, a deterministic discovery/marker strategy, or an explicit
ambiguous/manual-recovery state that forbids blind recreation. Provider
semantics must decide which of those contracts is honest.
The guidance is not currently executable as a product test. A test against
review double-submit, access-broker echo, admission reuse, or probe cleanup must
not be described as proof that export retry deduplicates resources.

The present YouTube probe test for ambiguous insert
(`tests/Unit/Integrations/PlatformAccess/YouTubeProbeTest.php:146-171`) returns a
malformed acknowledgement followed by empty reads. Response and simulated
external effect therefore still agree that nothing exists; it proves fail-closed
reporting, not discovery of an applied-but-unacknowledged effect.

#### Existing tests and cheapest layer

- `tests/Feature/ExportMatchReview/ConfirmExportReviewTest.php:86-99` proves
  repeat confirmation returns the same frozen manifest, not provider retry.
- `tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php:83-104`
  proves quota reservation reuse after later consumer failure, not resource reuse.
- `tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:312-360`
  proves an ambiguous database commit is recovered as one reservation.
- `tests/Unit/Integrations/PlatformAccess/YouTubeProbeTest.php:62-126,146-171,721-829`
  covers probe mutation and cleanup broadly but does not model an independent
  external ledger whose mutation survives a lost acknowledgement.

If current probe hardening is desired, the cheapest honest test is a unit test
at the HTTP edge with a stateful fake: POST changes a separate fake-provider
ledger and then throws; subsequent GET exposes the applied item; cleanup must
remove it. This remains a probe-cleanup test. The future export acceptance test
belongs at application integration level with database state plus the same kind
of independent provider ledger, asserting exactly one created resource.

#### Evidence correction

`app/Integrations/PlatformAccess/` is misleading as a product-export anchor. Its
churn may show provider-write complexity, but it contains operational canary
probes, not user export execution. The risk itself is supported by the PRD and
proposed S-06/S-07 behavior, not by an existing failure path in that directory.

### Risk #2 — partial export presented as complete or not safely resumable

#### Real failure path

No provider export state machine exists. `ExportReviewStatus` ends at review
states `queued`, `processing`, `ready`, `failed`, `confirmed`, and `expired`
(`app/Enums/ExportReviewStatus.php:5-12`). Confirmation atomically freezes
decisions and returns a manifest (`app/Actions/ExportReviews/ConfirmExportReview.php:32-41,86-141`):

```php
if ($locked->status === ExportReviewStatus::Confirmed) {
    return ConfirmedExportManifest::fromConfirmedReview(...);
}
// ...
$locked->forceFill(['status' => ExportReviewStatus::Confirmed, ...])->save();
```

The frozen destination is an account, not a provider playlist resource:
`ResolvedExportDestination` carries provider, destination type, local streaming
account ID, provider account ID, and market only
(`app/Actions/ExportReviews/ResolvedExportDestination.php:8-16`). The manifest
likewise has no destination playlist ID, logical write operation ID, partial
status, recovery cursor, or observed-content proof.

Consequently there is no current “final export success” to distrust and no
same destination playlist against which to resume. The risk is a valid future
acceptance requirement from FR-009–FR-011 and S-06/S-07, not a currently
exercisable production defect.

#### Guidance verdict

Keep the observable invariant but avoid prematurely mandating per-item cursors:

1. Persist a stable logical operation and provider playlist ID before later
   writes can be retried.
2. After an interruption, retain an explicit nonfinal/partial state tied to that
   same destination.
3. On retry, reconcile observed provider contents and safely apply the remainder
   or the complete desired state, depending on provider semantics.
4. Publish final success only after an observation proves the exact confirmed
   manifest is present. A write API's success response alone is not the oracle.

Spotify probe precedent is whole-list PUT followed by exact read-back
(`app/Integrations/PlatformAccess/SpotifyProbe.php:194-223`). YouTube probe
precedent is sequential POST plus exact read-back
(`app/Integrations/PlatformAccess/YouTubeProbe.php:227-320`). These are useful
provider-semantics examples, not export anchors.

#### Existing tests and cheapest layer

- `tests/Feature/ExportMatchReview/ConfirmedExportManifestTest.php:21-68`
  proves exact ordered handoff and no rematching.
- `tests/Feature/ExportMatchReview/ConfirmExportReviewTest.php:86-99` proves
  confirmation stability.
- `tests/Feature/ExportMatchReview/PrepareExportReviewTest.php:203-217` rejects
  partial *matching-stage* publication; it says nothing about provider writes.
- `tests/Unit/Integrations/PlatformAccess/YouTubeProbeTest.php:205-271` proves
  the probe waits for complete visibility and refuses persistently partial reads.

Do not add a fake export test before S-06/S-07. When a writer exists, use one
Laravel integration scenario with a stateful external HTTP fake: creation takes
effect; N item writes take effect; the next acknowledgement fails; local state
remains explicitly partial with the provider playlist ID; retry observes N items,
does not issue a second create, completes the same resource, and does not mark
success until read-back exactly equals the ordered manifest. A second case should
return write success but incomplete read-back to directly challenge the happy
path assumption. PostgreSQL is needed only if competing workers/locks become part
of the implementation; it is not intrinsically required for this risk.

#### Evidence correction

Risk #2 has no hotspot source in the plan. `PlatformAccess` is precedent only;
`PlaylistImport` is a read-side snapshot pipeline and does not own export
recovery. Treating either directory as the current failure location would be
misleading.

### Risk #5 — YouTube global admission, retry, reset, and concurrency

#### Real failure path

`ReserveYouTubeWrite::admit()` owns its transaction, refuses an ambient
transaction, and retries complete transactions only for PostgreSQL serialization
or deadlock SQLSTATEs (`app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php:29-64`).
Inside that transaction it applies a PostgreSQL local lock timeout, locks the
global singleton, samples Pacific time only after acquiring the lock, and checks
existing operation identity before reset/limit refusal (`:67-102`):

```php
$state = YouTubeWriteQuotaState::query()->whereKey('global')
    ->lockForUpdate()->first();
$now = CarbonImmutable::now(new DateTimeZone('America/Los_Angeles'));
$existing = YouTubeWriteAdmission::query()
    ->where('operation_type', $operationType->value)
    ->where('operation_id', $operationId)->first();
```

A new quota day resets count and snapshots the configured limit (`:104-121`).
Exhaustion creates no admission (`:123-131`). Creating an admission and
incrementing the counter occur in the same transaction (`:133-147`). Reset time
is next local calendar midnight, including DST behavior (`:179-185`). The schema's
unique composite key is the last-resort one-reservation invariant
(`database/migrations/2026_09_14_000300_create_youtube_write_admission_tables.php:26-34`).

However, repository-wide production use stops at the contract implementation
and service-provider binding. No writer calls the port. The primitive validates
only nonempty, bounded operation IDs (`ReserveYouTubeWrite.php:150-155`); semantic
stability, ownership, persistence before admission, and mandatory use all belong
to future consumers. PHP provides no architectural mechanism that prevents a
future writer from bypassing the port.

#### Guidance verdict

The production-atomicity warning is correct, but Phase 1 should **retain and run**
the existing PostgreSQL race suite rather than design another equivalent race.
The primitive already directly challenges both SQLite optimism and ambiguous
commit assumptions. Claims that every YouTube write is admitted, or that every
consumer retry uses the same logical ID, remain prospective until a consumer
exists.

#### Existing tests and cheapest layer

Default PHPUnit uses in-memory SQLite (`phpunit.xml:20`), which cannot prove
`FOR UPDATE`, lock timeouts, cross-process races, or PostgreSQL SQLSTATE behavior.
The SQLite feature suite still usefully covers:

- N admitted / N+1 refused and no extra row or count
  (`tests/Feature/Integrations/YouTubeWriteAdmission/ReserveYouTubeWriteTest.php:51-81`).
- Same-key retry across days, instances, and later consumer failure (`:83-104`).
- Different operation types with the same ID (`:107-116`).
- Reset/limit snapshot, atomic rollback, clock rollback, and PST/PDT boundaries
  (`:118-147,211-298`).

The existing PostgreSQL suite is already deliberate and production-shaped:

- Distinct-key race at limit three (`YouTubeWriteAdmissionPostgresTest.php:58-84`).
- Same-key race: one new plus three existing admissions, one row/count (`:86-106`).
- Contender blocked across Pacific midnight (`:108-207`).
- Fail-closed lock timeout (`:209-261`).
- Three full attempts for `40001` / `40P01`, with no partial state (`:263-310`).
- Lost commit acknowledgement followed by retry, yielding the original single
  reservation and count (`:312-360`).

CI provisions disposable PostgreSQL, installs `pdo_pgsql`/`pcntl`, migrates from
scratch, and runs the suite (`.github/workflows/ci.yml:84-124`). Local disposable
instructions exist in `README.md:200-225`. This research did not start the
disposable database, so it verifies that the path and cases exist, not that the
current commit is green on PostgreSQL.

The cheapest useful test today is the existing PostgreSQL feature suite. When
the first writer lands, add a consumer orchestration test proving stable identity
is persisted before admission and no HTTP mutation/access handoff follows
limit/unavailable. Retain one consumer-level PostgreSQL race in which two workers
start the same durable operation at the last slot and carry one reservation into
retry-safe orchestration.

#### Evidence correction

Neither `app/Integrations/PlatformAccess/` nor
`app/Integrations/PlaylistImport/` contains admission use. Both are misleading
as Risk #5 anchors. Risk #5 is supported by NFR-006 and future write slices; the
current implementation lives under `app/Integrations/YouTubeWriteAdmission/`.

### Risk #7 — failed read must not publish partial or falsely fresh data

#### Real failure path

Both provider readers complete the external edge before persistence. YouTube
performs bounded metadata and item calls and maps any failed response or transport
exception to a closed failure (`app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:39-65`).
It constructs a snapshot only after validating list shape, exactly one metadata
record, consistent declared/returned counts, no pagination, and every item
(`:68-152`). Spotify follows the same pattern (`SpotifyPlaylistReader.php:42-68,70-140`).

The empty cases are intentionally distinct:

- A successful response without required list structure is `InvalidResponse`
  (`YouTubePlaylistReader.php:76-85`).
- An explicit empty metadata `items` list is `PlaylistNotFound` (`:87-89`).
- A genuinely empty playlist is accepted only when metadata count, item-page
  total, and actual item count are all zero (`:101-127`).

Only a complete `PlaylistSnapshot` reaches persistence. `ImportPlaylist` returns
immediately on `ImportFailureCode`, then opens the transaction only for a valid
snapshot (`app/Actions/Playlists/ImportPlaylist.php:98-130`):

```php
if ($snapshot instanceof ImportFailureCode) {
    return $this->failure(...);
}
$playlist = DB::transaction(function () use ($snapshot, ...) { ... });
```

`ReplaceImportedPlaylist` performs parent upsert/lock/update, child deletion,
and child recreation in a transaction; freshness is updated with the same
snapshot (`app/Actions/Playlists/ReplaceImportedPlaylist.php:20-67,73-87`).
Scheduled YouTube refresh similarly reads before reconciliation
(`app/Actions/Playlists/RefreshYouTubePlaylistMetadata.php:18-40`), while
`ReconcileEditedYouTubePlaylist` locks and updates retained items and freshness
inside one transaction (`app/Actions/Playlists/ReconcileEditedYouTubePlaylist.php:18-83`).

Therefore a provider failure cannot publish a partial snapshot through the
implemented path, and it cannot advance `provider_metadata_refreshed_at`. An
independent 30-day retention command may later purge expired provider data; that
is deliberate fail-closed policy, not publication of an empty provider response
(`app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php:20-48,53-75`).

#### Guidance verdict

The response guidance is correct and the `PlaylistImport` hotspot is relevant,
though incomplete: the publish boundary lives in `app/Actions/Playlists/`, and
refresh retry/purge behavior also lives in `app/Jobs/` and `app/Console/Commands/`.
Fake the Laravel HTTP client, not `PlaylistSourceReader`, `ImportPlaylist`, or
`ReplaceImportedPlaylist`, so the test exercises response parsing, failure
mapping, transaction gating, and stored freshness together.

The current refresh job retries only rate- and quota-limited failures
(`app/Jobs/RefreshYouTubePlaylistMetadata.php:42-45`). A transport/provider-
unavailable result is logged and ends that job attempt; the daily schedule is
the next opportunity. This does not publish partial state or advance freshness,
but a test expecting an immediate same-job transport retry would encode behavior
the application does not provide.

#### Existing tests and cheapest layer

- Reader transport mapping is covered only at unit level
  (`tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php:82-90`).
- A YouTube malformed second response during reimport preserves the complete
  previous row and items (`tests/Feature/Playlists/PlaylistImportTest.php:170-185`).
- Spotify has the analogous preservation assertion
  (`tests/Feature/Playlists/SpotifyPlaylistImportTest.php:107-121`).
- Rate/quota refresh failures assert delayed retry and unchanged freshness
  (`tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php:82-123`).
- Invalid local occurrence identity during reconciliation rolls the complete
  state back (`tests/Feature/PlaylistEditing/YouTubeEditedPlaylistRefreshTest.php:83-106`).
- A legitimate zero-item shape is accepted at reader level
  (`tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php:17-36`).

The focused suite was run during this research pass:

```text
140 tests passed, 666 assertions
```

The deliberate PostgreSQL race suite was inspected but not executed locally:
this PHP runtime lacks `pdo_pgsql`, and the sandbox cannot access the Docker
daemon. CI and disposable-database wiring exist, but this artifact does not
claim a fresh PostgreSQL-green result.

The cheapest useful addition is one SQLite-backed feature/integration test with
an existing stored snapshot and the real YouTube reader: return valid metadata,
then `pushFailedConnection()` for the items call; assert the entire playlist,
items, and freshness remain byte-for-byte unchanged and two HTTP calls occurred.
Add a small reader/feature case in which HTTP 200 returns an empty or count-
inconsistent payload and prove it is failure, while the explicit consistent
zero-count shape remains a successful empty playlist. No browser or PostgreSQL
signal is needed for this path.

## Architecture Insights

The repository already has three strong but separate safety boundaries:

1. **Read then publish:** provider reads create a complete immutable snapshot;
   persistence atomically swaps it into the private bank.
2. **Freeze then hand off:** confirmation locks source/destination decisions and
   emits an exact ordered manifest.
3. **Admit once:** a PostgreSQL-serialized ledger reserves YouTube quota once per
   caller-provided logical identity.

Future S-06/S-07 must connect these boundaries with a fourth one: a durable
export operation that owns the stable ID, provider destination ID, explicit
nonfinal state, and reconciliation evidence. Reusing the review correlation ID
or another identity is a design choice; stability and persistence before the
first external effect are the required semantics.

The most important testing pattern is **independent observations**. A provider
fake must keep external state separately from its acknowledgement stream, so a
write can take effect while the caller observes a timeout. Assertions must then
compare database state, request history, and provider-observed state rather than
letting one scripted response stand in for all three.

## Historical Context

- `context/archive/2026-09-13-playlist-link-import/plan.md:128-148,283-293`
  established “complete read outside the transaction, atomic replacement inside”
  and explicitly required failure to leave the previous snapshot unchanged.
- `context/archive/2026-09-13-playlist-link-import/plan.md:499-550` established
  that freshness changes only with successful atomic refresh and that failure
  does not advance it.
- `context/archive/2026-09-14-export-match-review/plan.md:88-98` explicitly
  excluded provider playlist creation/update and partial-write retry from S-05.
- `context/archive/2026-09-14-youtube-write-admission/plan.md:5-13,74-79`
  established admission as a provider-agnostic primitive for future consumers,
  with no YouTube request in that slice.
- `context/archive/2026-09-14-youtube-write-admission/reviews/impl-review.md:47-58`
  records that PostgreSQL cases were skipped in that local review and delegated
  to CI. That is historical evidence, not a fresh green PostgreSQL run.

## Code References

- `app/Integrations/PlatformAccess/YouTubeProbe.php:227-320` — probe-only
  ambiguous mutation, exact read-back, and cleanup boundary.
- `app/Integrations/ExportMatching/Data/ConfirmedExportManifest.php:18-61` —
  frozen review handoff; no provider resource or retry state.
- `app/Integrations/YouTubeWriteAdmission/Actions/ReserveYouTubeWrite.php:29-185`
  — transaction, lock, retry, reset, and one-reservation logic.
- `tests/Feature/Integrations/YouTubeWriteAdmission/YouTubeWriteAdmissionPostgresTest.php:58-360`
  — production-database race and ambiguous-commit coverage.
- `app/Integrations/PlaylistImport/Providers/YouTubePlaylistReader.php:39-152`
  — complete HTTP read and validation before snapshot construction.
- `app/Actions/Playlists/ImportPlaylist.php:98-138` — failure gates persistence.
- `app/Actions/Playlists/ReplaceImportedPlaylist.php:20-67` — atomic snapshot
  replacement.
- `tests/Feature/Playlists/PlaylistImportTest.php:170-185` — failed reimport
  preserves previous snapshot.
- `tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php:82-123` — failed
  refresh does not claim freshness.

GitHub permalinks were not generated: `gh repo view` could not resolve the
configured SSH remote as a known GitHub host in this environment. References
therefore use repository-relative paths and line numbers.

## Related Research

No other `research.md` artifact exists under `context/changes/` or
`context/archive/` at the time of this investigation. Relevant prior decisions
are captured in the archived plans listed above.

## Open Questions

1. Which durable export entity will own the logical operation ID and provider
   destination playlist ID before the first mutation?
2. What are the legal export status transitions, and which state is user-visible
   after an ambiguous or partial effect?
3. For each provider, is resume implemented by checkpointed remainder, full
   desired-state replacement, or read/reconcile? The tests should assert the
   invariant, not assume one mechanism prematurely.
4. What read-back constitutes final proof for duplicate occurrences and order?
5. Should current probe cleanup gain an independent stateful-fake regression, or
   should Phase 1 limit itself to the product-facing Risk #7 gap plus retention of
   the existing PostgreSQL admission suite?
6. The disposable PostgreSQL path exists but was not run in this research. The
   implementation phase should execute it before claiming the production race
   gate green.
7. Should transport/provider-unavailable metadata refresh failures receive a
   bounded same-job retry, or is the next daily schedule the intended policy?
