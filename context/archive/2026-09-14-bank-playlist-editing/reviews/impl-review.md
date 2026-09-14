<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Edycja playlisty w banku

- **Plan**: context/changes/bank-playlist-editing/plan.md
- **Scope**: Phases 1–4 of 4 (implemented automation; manual verification pending)
- **Date**: 2026-09-14
- **Verdict**: APPROVED — all implementation-review findings fixed
- **Findings**: 0 critical, 3 warnings, 0 observations (3 fixed)

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Findings

### F1 — Client-writable draft can fail before save validation

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: app/Livewire/PlaylistEditor.php:19
- **Detail**: `orderedItemIds` is the only client-carried snapshot property without `#[Locked]`. A client update can replace it with values that never passed through `moveUp`, `moveDown`, or `removeItem`. A string entry reaches the strictly typed render callback at lines 134–136 and raises `TypeError`; an unknown integer reaches `resources/views/livewire/playlist-editor.blade.php:31` and dereferences a missing `itemDetails` entry. `UpdateBankPlaylistItems` validates on save, but Livewire can render the corrupted public state before save runs.
- **Fix**: Add `#[Locked]` to `orderedItemIds` and add a Livewire test proving client-side mutation is rejected while server methods can still reorder and remove entries.
- **Decision**: FIXED — added `#[Locked]` and a client-mutation regression test; 8 component tests and Pint pass.

### F2 — PostgreSQL CI does not execute the new race test

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: .github/workflows/ci.yml:139
- **Detail**: Progress marks the PostgreSQL race and matrix complete (items 1.5 and 4.7), and `UpdateBankPlaylistItemsPostgresTest.php` contains the intended concurrent-write proof. The `postgres-smoke` command does not include that file or the `tests/Feature/PlaylistEditing` directory. Locally the test is skipped on SQLite, so the committed pipeline currently has no path that executes this proof on PostgreSQL.
- **Fix**: Add `tests/Feature/PlaylistEditing/UpdateBankPlaylistItemsPostgresTest.php` to the `postgres-smoke` test command.
- **Decision**: FIXED — added the race test to `postgres-smoke`; the 139-assertion infrastructure contract passes.

### F3 — Exact 20-item boundary has no positive fixture

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/changes/bank-playlist-editing/plan.md:464
- **Detail**: Phase 4 requires fixtures for 0, 1, and 20 items. The suite covers empty and single-item behavior and proves that 21 IDs are rejected, but no S-03 test creates exactly 20 items and proves that the allowed boundary can be reordered/saved with continuous positions. This leaves an explicit plan contract unimplemented.
- **Fix**: Add an action or Livewire feature test with exactly 20 items that saves a valid reorder and asserts the complete `0..19` position sequence.
- **Decision**: FIXED — added an isolated 20-item reorder test that asserts IDs and continuous positions `0..19`.

## Verification Evidence

### Automated

| Command | Result | Evidence |
|---------|--------|----------|
| `php artisan test tests/Unit/Playlists/FingerprintPlaylistContentTest.php tests/Feature/PlaylistEditing/UpdateBankPlaylistItemsTest.php` | PASS | 7 tests, 30 assertions |
| `php artisan test tests/Feature/PlaylistEditing tests/Feature/BankAccessTest.php` | PASS with expected environment skip | 31 passed, 1 PostgreSQL-only skipped; 161 assertions |
| `php artisan test tests/Feature/PlaylistEditing tests/Feature/Playlists/PlaylistImportTest.php tests/Feature/Playlists/YouTubeMetadataLifecycleTest.php tests/Feature/Playlists/SpotifyPlaylistImportTest.php` | PASS with expected environment skip | 63 passed, 1 PostgreSQL-only skipped; 294 assertions |
| `php artisan test tests/Unit/Playlists tests/Feature/PlaylistEditing` | PASS with expected environment skip | 27 passed, 1 PostgreSQL-only skipped; 138 assertions |
| `php artisan test tests/Feature/Playlists tests/Feature/StreamingAccounts` | PASS with expected environment skip | 75 passed, 1 PostgreSQL-only skipped; 408 assertions |
| `composer test` | PASS with environment skips | Post-triage run: 375 passed, 2 PostgreSQL-only skipped; 2,334 assertions |
| `vendor/bin/pint --test` | PASS | Pint reported `passed` |
| `npm run build` | PASS | Vite 8.2.2 production build completed |
| `sh scripts/verify-source-contract --worktree` | PASS | `source contract: PASS (--worktree)` |
| `composer audit --locked --no-interaction` | PASS | No security vulnerability advisories found |
| `npm audit --audit-level=high` | PASS | 0 vulnerabilities |
| PostgreSQL race execution | PENDING CI / not locally exercised | Local SQLite skips it; post-triage CI configuration now includes the race test (F2) |

### Manual

Plan progress is 27/37 (73.0%). Manual checks 1.7 and 4.12 passed during
post-review contract and migration inspection. The remaining 10 manual checks
(2.7–2.9, 3.7–3.9, and 4.8–4.11) remain for verification against the deployed
candidate. The former S-05 fingerprint-acceptance gate was removed from S-03;
S-05 adopts the shared contract during its own integration.

## Scope Notes

- Runtime implementation matches the four phase contracts outside the findings above; no `Czego NIE robimy` boundary was violated.
- No concrete injection, XSS, secret exposure, authorization, CORS, N+1, unbounded-work, provider-boundary, migration rollback, or destructive-data issue was found.
- `context/foundation/roadmap.md` already had an uncommitted `proposed` → `in-progress` edit when this review began. The review preserves it and does not attribute it to the committed S-03 diff.

## Triage Summary

- **Fixed**: F1, F2, F3 (3)
- **Rule**: none
- **Skipped**: none
- **Accepted**: none

Targeted post-triage verification passed with 22 tests and 204 assertions. Pint passed. The full Composer suite passed with 375 tests, 2 PostgreSQL-only skips, and 2,334 assertions. The PostgreSQL race test is now wired into its intended CI environment; its next pipeline run supplies the environment-specific execution evidence. Manual verification follows this approved implementation review by design.
