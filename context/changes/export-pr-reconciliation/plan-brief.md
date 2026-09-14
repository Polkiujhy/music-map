# Integracja eksportu i synchronizacji — krótki plan

> Pełny plan: [plan.md](plan.md)
> Badania: [research.md](research.md)
> Dowody lokalne: [implementation.md](implementation.md)

## Co i dlaczego

S-11 `export-pr-reconciliation` zastępuje, scala i domyka S-06, S-07 oraz S-08.
Eksport linked/managed i synchronizacja źródła mają współdziałać bez przejęcia
niewłaściwej playlisty, utraty banku i regresji odzyskiwania po awarii.

## Punkt wyjścia

PR #59/#61/#62 są scalone lokalnie; kod `97e1086`, epilog `e05bb5b`.
SQLite 743 passed/21 skipped; PostgreSQL 15: 764/764. Live smoke nie wykonano.
Ta korekta zachowuje ukończone kroki i dodaje wspólną, nadal otwartą akceptację.

## Pożądany stan końcowy

Użytkownik przenosi playlistę na wybrane konto przy aktywnym sync źródła,
widzi właściciela i stały wynik, a retry nie tworzy niekontrolowanej drugiej kopii.
S-06/S-07/S-08 zamyka jeden zaakceptowany kandydat S-11, nie trzy osobne odbiory.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Silnik eksportu | Trwały lifecycle #61 także dla linked | Jedna historia celu i retry | Badania / integracja |
| Sync | Odrębne runy, source-wins | Nie miesza frozen export z bieżącym źródłem | #59 / integracja |
| Schemat | Relacja i target attempts, bez drugiego modelu #62 | Chroni istniejące locatory i własność | Integracja |
| Recreate | Jawna nowa generacja, zachowana historia | Nie traci śladów nieznanego create | #61 / integracja |
| Dostęp | Linked OAuth; managed publiczny kontrakt v1 | Brak mechaniki PaaS i sekretów w domenie | s-manager-use |
| Akceptacja | 18 wspólnych bramek z mapowaniem 37 źródłowych punktów | Nic nie ginie przy deduplikacji | Użytkownik / plan |
| Domknięcie | S-11 zastępuje trzy wycinki; nadal in-progress | Merge nie jest akceptacją live | Użytkownik / roadmapa |

## Zakres

W zakresie: wspólny model eksportu, granice sync, regresja, manual checks,
publikacja addytywna jako przyszła bramka, safe smoke i cleanup.
Poza zakresem: S-09, S-10, drugi runtime, nowe możliwości PaaS.
Obecne zlecenie dokumentacji nie upoważnia do push, zdalnego merge ani deploy/live writes.

## Architektura / Podejście

Review zamraża wejście; wspólny eksport zapisuje osobny cel. Sync uzgadnia
bank z własnym źródłem. Oba eksporty i sync korzystają z jednego admission F-02,
zachowując osobne logiczne operation IDs i granice własności.

## Fazy w skrócie

| Faza | Rezultat | Ryzyko |
| --- | --- | --- |
| 1 — zakończona | Połączone historie i schemat | Dwie definicje operacji |
| 2 — zakończona | Wspólny eksport i recovery | Identity, partial write, drugi create |
| 3 — zakończona | Granice sync/import/maintenance | Przejęcie źródła lub celu |
| 4 — zakończona lokalnie | SQLite/PG, build i dowody | Lokalny wynik nie oznacza CI/live |
| 5 — otwarta | Wspólna akceptacja 18 bramek | Konta, PaaS, smoke, cleanup i poufność |

Wymagania wstępne: F-02, S-03, S-04, S-05; przed smoke zgodny kandydat,
osobne upoważnienie, dedykowane konta obu providerów i potwierdzony kontrakt PaaS.

## Otwarte ryzyka i założenia

- Realne markery, duplikaty YouTube i pełny scan kont managed do 1000 playlist.
- Produkcyjne write/rotation v1: statyczna zgodność nie zastępuje operatora/smoke.
- Ewentualne osobne wdrożenie któregoś PR wymaga przeglądu migracji danych.
- Niewykonalny bezpiecznie live fault pozostaje otwarty; mock nie zalicza bramki.

## Kryteria sukcesu (podsumowanie)

- Cztery warianty eksportu oraz source sync obu providerów przechodzą razem.
- Retry, source-wins, UI, notification i poufność mają dowody dokładnego kandydata.
- Cleanup i odbiór PostgreSQL/rotacji zamykają checklistę przed archiwizacją S-11.
