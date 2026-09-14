# Managed account export — manual release verification

> Historical PR evidence/procedure. Current execution and acceptance belong to
> [S-11 export-pr-reconciliation](../../export-pr-reconciliation/plan.md), which supersedes
> S-06/S-07/S-08. Preserve this record; do not maintain a second live checklist.
> Its old results or optional scenarios do not satisfy the consolidated candidate gates.

Status: PENDING — controlled live smoke has not been run.

This record is intentionally secret-free. Do not add account IDs, playlist IDs,
tokens, provider payloads, provider-controlled error text, or PaaS implementation
details. Keep those values only in the approved operational system.

## Automated candidate evidence

- Date: 2026-09-14
- Candidate: Phase 5 worktree based on commit `115e9d8`
- Database: disposable PostgreSQL 18.6, rebuilt with `migrate:fresh`
- Full suite: PASS — 591 tests, 3558 assertions, 1 warning, 1 notice
- Managed-export concurrency: PASS — real locks, double confirmation, worker claim,
  canonical target relation, retry serialization, and F-02 admission executed without skips
- Dependency audits: PASS — Composer reported no advisories; npm reported no vulnerabilities
- Source contract, Pint, and production frontend build: PASS

This automated evidence does not substitute for the controlled provider smoke and
published rotation-contract confirmation below.

## Exact candidate

- Commit: PENDING
- Release: PENDING
- Verifier: PENDING
- UTC window: PENDING
- Public technical refresh-token rotation contract version: `music-map.managed-export.v1`
- Static contract compatibility and synchronous hand-off guarantee: PASS — consumer schema matches the published `s-manager-use/references/music-map-managed-export.md` contract
- Live/operator confirmation on the exact candidate: PENDING

## Spotify technical account

- Result (PASS/FAIL): PENDING
- Visibility is `public=false`: PENDING
- Stable link opens while the playlist is absent from the public profile: PENDING
- Recovery marker survives a read round trip: PENDING
- Create and update retain the same provider ID: PENDING
- Controlled interruption followed by exact retry completed: PENDING
- Cleanup completed: PENDING

Evidence reference (non-sensitive): PENDING

## YouTube technical account

- Result (PASS/FAIL): PENDING
- Visibility is `unlisted`: PENDING
- Stable link opens: PENDING
- Recovery marker survives two stable read observations: PENDING
- Create and update retain the same provider ID: PENDING
- Controlled partial interruption retries the same F-02 reservation: PENDING
- Cleanup completed: PENDING

Evidence reference (non-sensitive): PENDING

## Secret-free review

- Application logs contain no secrets, account IDs, provider playlist IDs, or raw responses: PENDING
- Failed-job records contain no secrets, account IDs, provider playlist IDs, or raw responses: PENDING
- Completion mail contains only the approved stable link and user-safe copy: PENDING
- This artifact contains none of the prohibited identifiers or payloads: PENDING

## Release decision

- Result (PASS/FAIL): PENDING
- Notes (user-safe and identifier-free): PENDING
