# Wynik lokalnej integracji PR #59, #61 i #62

## Zakres i tożsamość źródeł

Zgoda użytkownika: „wykonaj scalenie”. Gałąź `integrate/pr-59-61-62`,
baza `origin/main` z commit `38d6db8`. Rodzice integracji:

- #59: `61de763d5f6e2571b6f342c46daccda5cb60334a`.
- #61: `67655190da5daaf6f9883a2a318e161bf32fd29b`.
- #62: `f40b1c5c08a28f8080ae3e4590569643f60a34e8`.

Nie wykonano push, zdalnego merge PR-ów, deploy ani rzeczywistych mutacji
Spotify/YouTube. Integracja nie oznacza zakończenia bramek live smoke tych PR-ów.

## Rozstrzygnięcia integracji

- Jeden trwały lifecycle eksportu #61: UUID `export_operations`,
  `playlist_exports`, historia `playlist_export_target_attempts`, generacje
  retry/claim, odbudowa publikacji kolejki i świadome recreate. Linked z #62
  korzysta z tego samego mechanizmu, lecz z OAuth użytkownika.
- `origin=managed_target` i nazwy klas/tras `ManagedExport` zachowane jako
  kompatybilny kontrakt wewnętrzny. Chronią wszystkie cele eksportu, także linked.
  Nie wprowadzamy równoległych `role`, `playlist_export_links` ani drugiej migracji
  `create_export_operations`. Nowa migracja tylko zamraża metadane operacji.
- Zachowana historyczna tożsamość konta. Reconnect tej samej tożsamości może
  zmienić lokalny rekord, ale nie cel. Kontrola tokenu/wersji credential i generacji
  wykonawcy przed każdą mutacją. Minimalna ważność tokenu na start: 390 sekund.
- Tracks, nazwa i opis po confirm są zamrożone. Historyczny confirmed bez
  operacji nie uruchamia nowego eksportu. Ponowiony confirm nie publikuje duplikatu.
- Linked może przywrócić zmienione metadane znanego celu po ID; wyszukanie
  nieznanego wyniku create nadal wymaga markera. Niejednoznaczny create nie
  powoduje automatycznego utworzenia drugiej playlisty.
- YouTube zachowuje istniejące occurrence IDs, dopisuje brakujące pozycje,
  usuwa nadmiar i przesuwa tylko potrzebne elementy. Duplikaty i retry po utracie
  odpowiedzi mają testy; końcowy pełny stan jest weryfikowany dwoma odczytami.
- Source sync #59 ma osobny lifecycle i regułę source-wins. Eksporty korzystają
  z zamrożonego manifestu, a trzy typy zapisów używają wspólnego YouTube admission.
- Spotify private export zachowuje historyczne scopes; wymaganie
  `playlist-modify-public` dotyczy source sync, nie prywatnego eksportu.
- Bank, review i e-mail rozróżniają właściciela linked/managed. Potwierdzenie
  ostrzega, że usunięcie z banku może być wypchnięte do oryginału przez auto-sync.

## Dodatkowe zabezpieczenie znalezione podczas review

Znany provider ID celu, także przed materializacją i po częściowej awarii,
nie może być zaimportowany jako źródło. Kontrola obejmuje historyczne próby
i użytkownika/providera. Import oraz publikacja lokatora serializują się na
wierszu użytkownika (`FOR NO KEY UPDATE` na PostgreSQL, bez blokady kluczy obcych).
Nie trzymają tej blokady przez provider I/O. Jeśli import wygrał przed poznaniem
lokatora, eksport zatrzymuje reconcile przed mutacją; nie zmienia źródła w cel.
Usunięta jest też kolizja typów, w której import wywoływał metody modelu na enumie
`ImportFailureCode`. Kontrole źródła obowiązują przy HTTP, akcjach, workerach
i maintenance; maintenance wyklucza zarówno cele, jak i aktywne synchronizacje.

## Mapowanie zastąpionych testów #62

Testy drugiego runtime (`Feature/Exports`, `Unit/Integrations/PlaylistExport`)
nie są kopiowane pod nieistniejący schemat. Ich kontrakty pokrywają istniejące
testy #61 oraz nowe:

| Kontrakt | Pokrycie na wspólnym modelu |
| --- | --- |
| Linked Spotify/YouTube, tożsamość, token, unlink | `LinkedExportWorkflowTest`, gateway/access unit tests |
| Retry/recreate i wymiana rekordu konta | `LinkedExportRecoveryTest`, `ManagedExportRecoveryTest`, `ManagedExportRouteTest` |
| Kolejka, claim, generacje i publication recovery | `ManagedExportRecoveryTest`, `RunManagedExportTest`, `ManagedExportPostgresTest` |
| UI, bank, własność, powiadomienia | `LinkedExportUiTest`, `ManagedExportBankTest`, `ManagedExportNotificationTest` |
| Źródło/target i kolizja importu | `ExportTargetBoundaryTest`, `DurableExportTargetImportTest`, `ExportIntegrationBoundaryTest` |
| Zamrożenie i historyczny confirm | `StartManagedExportTest`, `ExportMetadataMigrationTest` |
| Globalny limit i rezerwacje retry | `RunManagedExportTest`, `ManagedExportPostgresTest`, source-sync admission tests, F-02 tests |
| Minimalne mutacje YouTube i dokładny wynik | `YouTubeManagedPlaylistGatewayTest`, `ManagedExportMaterializationTest`, `ManagedExportWorkflowTest` |

Pełny zestaw uruchamiany także na trwałej bazie PostgreSQL, nie tylko `:memory:`.
W ten sposób kontrolujemy również izolację testów, która była problemem #62.

## Weryfikacja

Końcowe wyniki z 2026-09-14:

- SQLite: 764 testy, 743 passed, 21 skipped (testy wymagające PostgreSQL),
  4418 asercji, brak błędów.
- PostgreSQL: 764/764 passed, 4592 asercje, brak pominięć i błędów.
  Obejmuje prawdziwy wyścig importu z publikacją lokatora oraz migration backfill.
- `vendor/bin/pint --test`: PASS.
- `npm run build`: PASS.
- `scripts/verify-source-contract --worktree` i `--tracked`: PASS.
- `composer validate --strict --no-interaction`: PASS.
- `git diff --check`: PASS.

PostgreSQL 15 uruchomiono w jednorazowym katalogu poza repo; sterownik pdo_pgsql
załadowano tylko dla procesu testowego. Baza aplikacji i konfiguracja hosta pozostały
bez zmian. CI osobno używa PostgreSQL 18; nie zastępujemy jego wyniku lokalnym testem.
