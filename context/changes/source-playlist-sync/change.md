---
change_id: source-playlist-sync
title: Synchronizacja playlisty źródłowej
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

Plan dla wycinka roadmapy S-08. Obejmuje świadomie włączaną, ręczną i
automatyczną synchronizację własnej playlisty źródłowej Spotify lub YouTube z
bankiem. Konflikty rozstrzyga na korzyść aktualnego źródła, a zapisy YouTube
korzystają ze wspólnego dopuszczenia i stabilnej tożsamości operacji.
