---
change_id: managed-account-export
title: Eksport na konto techniczne music-map
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

Plan dla wycinka roadmapy S-06. Wymagania wstępne F-02 i S-05 są ukończone.
Zmiana obejmuje zarządzany eksport Spotify i YouTube, ale zachowuje Manager jako
zewnętrzny PaaS i nie przenosi jego mechaniki do repozytorium aplikacji.
