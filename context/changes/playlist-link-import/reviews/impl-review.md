<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Import playlisty z linku do prywatnego banku

- **Plan**: context/changes/playlist-link-import/plan.md
- **Scope**: Phases 1–5 of 5
- **Date**: 2026-09-14
- **Verdict**: REJECTED
- **Findings**: 1 critical, 6 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | FAIL |
| Scope Discipline | WARNING |
| Safety & Quality | FAIL |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

## Findings

### F1 — Spotify import expects the obsolete metadata shape

- **Severity**: ❌ CRITICAL
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Integrations/PlaylistImport/Providers/SpotifyPlaylistReader.php:91
- **Detail**: The reader requires `tracks.total` from `GET /playlists/{id}` and rejects the response when it is absent. The current Spotify response schema exposes the playlist paging object as top-level `items`, with the count at `items.total` (https://developer.spotify.com/documentation/web-api/reference/get-playlist). A valid live response therefore reaches `InvalidResponse`, while both Spotify test fixture builders repeat the obsolete `tracks` shape and cannot detect the contract failure.
- **Fix**: Read and validate `items.total`, update both Spotify fixture builders to the current official response shape, and add an explicit current-schema regression case.
- **Decision**: FIXED

### F2 — PostgreSQL CI omits the promised simultaneous-import case

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/Playlists/PlaylistPersistenceTest.php:17
- **Detail**: Phase 5 promises a PostgreSQL matrix for ownership, simultaneous/repeated import, and child replacement. PR #44's `postgres-smoke` job is green and runs this test file, but the file contains only per-user uniqueness, sequential reimport, rollback, and model-cast cases. No test exercises two first imports racing on the unique key, so the most database-specific concurrency promise is unverified.
- **Fix**: Add a PostgreSQL-capable concurrent first-import test that proves one playlist row and one complete snapshot remain after both contenders finish.
  - Strength: Exercises the exact race the upsert-and-lock design was selected to close.
  - Tradeoff: Requires deterministic multi-connection/process coordination and should be skipped or adapted on SQLite.
  - Confidence: HIGH — the missing scenario is named explicitly in the plan and absent from the test class.
  - Blind spot: The hosted CI service passed, but its logs do not create coverage that the invoked tests lack.
- **Decision**: FIXED

### F3 — Web failure assertions do not meet the planned matrix

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: tests/Feature/Playlists/PlaylistImportTest.php:52
- **Detail**: Phase 2 requires every actionable failure message at feature level and requires the displayed correlation UUID to equal the UUID in the sanitized log event. The YouTube feature test checks only that both locations contain a UUID-like concept independently; it never compares their values. It also lacks feature-level message cases for the reader's 403/404/429/quota/5xx/malformed/unsupported/over-limit outcomes. Unit tests cover mapping, but not controller copy or end-to-end propagation.
- **Fix**: Add a data-driven YouTube feature failure matrix and capture the flash/log UUIDs in the same test so their exact equality is asserted.
- **Decision**: FIXED

### F4 — Purged YouTube shells are rewritten every day forever

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Console/Commands/RefreshStaleYouTubePlaylistMetadata.php:23
- **Detail**: The expiry query selects every YouTube row whose freshness timestamp is at least 30 days old. Purging clears provider fields and items but leaves that timestamp unchanged, so already-empty shells are selected, locked, deleted from, and updated on every daily run. Work and writes grow with the cumulative number of expired shells.
- **Fix A ⭐ Recommended**: Restrict the purge query to rows that still contain API-derived metadata or items.
  - Strength: Stops repeat writes without a schema change and preserves the durable shell/canonical link contract.
  - Tradeoff: The predicate must cover every provider-derived field consistently.
  - Confidence: HIGH — the current purge leaves a readily detectable empty shell.
  - Blind spot: A future provider-derived column would need to be added to the predicate.
- **Fix B**: Add an explicit `provider_metadata_purged_at` state column and query only unpurged expired rows.
  - Strength: Makes lifecycle state explicit and extensible.
  - Tradeoff: Adds a migration, model state, and broader test surface for a narrow issue.
  - Confidence: MEDIUM — cleaner long-term semantics, but likely heavier than S-02 needs.
  - Blind spot: The field's interaction with later reimport/synchronization work is not yet designed.
- **Decision**: FIXED via Fix A

### F5 — Spotify metadata is displayed without required logo attribution

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: resources/views/bank/index.blade.php:68
- **Detail**: Phase 4 says Spotify attribution is added with the adapter. The card renders the word `Spotify` and a source link, but no Spotify logo/approved attribution treatment. Spotify's current endpoint policy requires displayed Spotify metadata to link back to Spotify and to be attributed with the logo (https://developer.spotify.com/documentation/web-api/reference/get-playlist).
- **Fix**: Add an approved, accessible Spotify logo attribution beside the Spotify source link and cover its presence in feature/manual UI verification.
  - Strength: Satisfies the provider rule at the exact point where imported metadata is displayed.
  - Tradeoff: Requires selecting and maintaining an official brand asset/treatment.
  - Confidence: HIGH — the official policy is explicit and no Spotify logo exists in the rendered resources.
  - Blind spot: Final visual sizing/clear-space compliance still needs owner review.
- **Decision**: FIXED

### F6 — Privacy copy promises a deletion function that does not exist

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: resources/views/legal/privacy.blade.php:11
- **Detail**: The privacy notice says a user can delete their account and related data through available service functions, but the route table contains no account/profile deletion endpoint or UI. Account deletion belongs to future slice S-10, so the present statement extends the delivered behavior and is inaccurate for S-02.
- **Fix**: Remove or rewrite the sentence to state only the deletion/request mechanism that is actually available until S-10 lands.
- **Decision**: FIXED

### F7 — All 14 manual acceptance gates remain pending

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Success Criteria
- **Location**: context/changes/playlist-link-import/reviews/manual-verification.md:5
- **Detail**: Every manual Progress item across phases 1–5 remains unchecked, and the release-evidence template still contains only `Pending` values. This includes owner policy/UX approval, live YouTube and Spotify import/refusal smoke, runtime key readiness, schema release, accessibility/responsiveness, and secret/raw-data inspection. The implementation can be code-reviewed, but it is not release-accepted.
- **Fix**: Execute the safe manual checklist against the exact candidate, record only the non-secret evidence requested by the template, and then update the matching Progress boxes.
  - Strength: Closes the plan's explicit external, policy, accessibility, and live-provider risks.
  - Tradeoff: Requires operator credentials, approved fixtures, staged/runtime access, and owner participation.
  - Confidence: HIGH — both the canonical Progress section and evidence artifact mark every manual result pending.
  - Blind spot: This review cannot perform or infer human acceptance and live-account outcomes.
- **Decision**: ACCEPTED — owner is actively completing manual verification separately

### F8 — Bank cards are loaded without pagination

- **Severity**: 👁 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: app/Http/Controllers/BankController.php:12
- **Detail**: The bank materializes every owned playlist plus two count subqueries and renders every card. Provider item reads are bounded, but a user's bank cardinality is not. The plan explicitly assumes small scale, so this is not a current rejection condition, but request cost grows without a ceiling.
- **Fix**: Define the bank-size threshold that ends the small-scale assumption and add pagination before that threshold is reached.
  - Strength: Prevents an avoidable future latency/memory cliff while preserving owner-scoped querying.
  - Tradeoff: Pagination changes the view/controller contract and is not required for the stated initial scale.
  - Confidence: HIGH — the terminal `get()` is unbounded and no surrounding product limit caps playlist count.
  - Blind spot: No production cardinality or latency data exists yet.
- **Decision**: SKIPPED — deferred while the small-scale assumption holds

## Verification Evidence

- `php artisan test tests/Unit/Integrations/PlaylistImport` — PASS, 50 tests / 101 assertions.
- `php artisan test tests/Feature/Playlists/PlaylistMigrationTest.php` — PASS, 1 test / 5 assertions.
- `php artisan test tests/Feature/Playlists/PlaylistPersistenceTest.php` — PASS, 4 tests / 20 assertions.
- `php artisan test tests/Feature/Auth/AuthIdentityModelTest.php tests/Feature/BankAccessTest.php` — PASS, 10 tests / 33 assertions.
- `php artisan test tests/Unit/Integrations/PlaylistImport/YouTubePlaylistReaderTest.php` — PASS, 14 tests / 33 assertions.
- `php artisan test tests/Feature/Playlists/PlaylistImportTest.php tests/Feature/BankAccessTest.php` — PASS, 13 tests / 71 assertions.
- `php artisan test tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php` — PASS, 9 tests / 40 assertions.
- `php artisan test tests/Unit/Integrations/PlaylistImport/SpotifyPlaylistReaderTest.php` — PASS, 16 tests / 33 assertions.
- `php artisan test tests/Feature/Playlists/SpotifyPlaylistImportTest.php` — PASS, 12 tests / 38 assertions.
- `php artisan schedule:list` — PASS, exactly one daily `playlists:refresh-youtube-metadata` entry.
- `composer test` — PASS, 336 tests / 2,129 assertions.
- `vendor/bin/pint --test` — PASS.
- `npm run build` — PASS.
- `sh scripts/verify-source-contract --worktree` — PASS.
- `composer audit --locked --no-interaction` — PASS, no advisories.
- `npm audit --audit-level=high` — PASS, 0 vulnerabilities.
- GitHub PR #44 CI at current HEAD — PASS for `application`, `postgres-smoke`, `source-security`, and `container-images`.

## Triage Summary

- **Fixed**: F1, F2, F3, F4 (Fix A), F5, F6
- **Accepted**: F7 — owner is actively completing manual verification separately
- **Skipped**: F8 — deferred while the small-scale assumption holds
- **Post-triage status**: NEEDS ATTENTION — code findings are addressed, but manual acceptance and execution of the new concurrent test on PostgreSQL remain pending
- **Final local verification**: PASS — 346 tests (345 passed, 1 PostgreSQL-only skipped), 2,167 assertions; Pint, production frontend build, source manifest, and diff check passed
