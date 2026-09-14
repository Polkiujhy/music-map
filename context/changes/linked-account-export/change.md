---
change_id: linked-account-export
title: Eksport na powiązane i zarządzane konto
status: impl_reviewed
created: 2026-09-14
updated: 2026-09-14
---

## Notes

2026-09-14: zastąpione wykonawczo przez S-11
[`export-pr-reconciliation`](../export-pr-reconciliation/plan.md).
Wspólna zmiana scala i prowadzi do domknięcia S-06/S-07/S-08; przejmuje manual
checks z zachowaniem mapowania starych numerów. Status tego artefaktu opisuje
historyczny PR, nie gotowość scalonego wydania. Nie uruchamiać osobnego planu
ani odbioru; domknięcie nastąpi po akceptacji S-11 i późniejszej archiwizacji.

Plan obejmuje wspólny silnik wykonawczy dla wycinków roadmapy S-07
`linked-account-export` oraz S-06 `managed-account-export`, zgodnie z decyzją
podjętą podczas planowania.

2026-09-14: lokalne scalenie PR #59/#61/#62 zastępuje równoległy runtime tego
planu trwałym lifecycle #61, rozszerzonym o zachowanie linked i zabezpieczenia #62.
Kanoniczny opis integracji: `../export-pr-reconciliation/plan.md` oraz
`../export-pr-reconciliation/implementation.md`. Ten plan i review pozostają
zapisem historycznym PR #62, nie instrukcją uruchamiania drugiego silnika.
