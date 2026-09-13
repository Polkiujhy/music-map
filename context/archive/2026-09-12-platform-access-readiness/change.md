---
change_id: platform-access-readiness
title: Gotowość dostępu do Spotify i YouTube
status: archived
created: 2026-09-12
updated: 2026-09-13
archived_at: 2026-09-13T22:41:13Z
---

## Notes

**Completion:** DONE — implementacja oraz weryfikacja automatyczna i ręczna zostały zakończone przed archiwizacją.

Manager pozostaje właścicielem OAuth, rotacji, dostarczania, revoke, recovery
i uruchamiania prób jako zewnętrzny PaaS. Aplikacja implementuje wyłącznie
publiczny probe `music-map.platform-access.v1`; jego kompletny kontrakt
konsumencki jest utrwalony w `plan.md`.
