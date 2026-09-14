---
change_id: testing-ambiguous-outcomes-safe-retry
title: Test ambiguous outcomes and safe retries
status: implementing
created: 2026-09-14
updated: 2026-09-14
archived_at: null
---

## Notes

Open a change folder for rollout Phase 1 of context/foundation/test-plan.md: "Ambiguous outcomes and safe retry".
Risks covered: #1, #2, #5, #7. Test types planned: contract + integration + PostgreSQL.
Risk response intent:
- #1: Prove retry recognizes an earlier external effect and never creates a second resource.
- #2: Prove partial work remains explicit and resumable against the same destination.
- #5: Prove limit, retry, reset, and concurrency preserve one reservation per logical operation.
- #7: Prove provider failure publishes neither a partial snapshot nor a false freshness state.
After creating the folder, follow the downstream continuation rule.
