# Plan: integracja PR 59, 61 i 62

## Scope

Lokalne scalenie na integrate/pr-59-61-62, autoryzowane przez użytkownika.
Zachować jeden lifecycle eksportu oparty o trwały model #61; przenieść zachowanie
linked i zabezpieczenia #62. Source sync #59 zachowuje własny lifecycle.
Bez push, zdalnego merge, deploy i rzeczywistych zapisów providerów.

## Phase 1: Połączenie historii i schematu

Scalić aktualne main i trzy PR-y. Rozwiązać kolizje modeli, migracji, rejestracji,
tras i maintenance. Jedna tabela export_operations, jedna historia celu i retry.
Usunąć zastąpione implementacje, zachowując testy kontraktów przenoszonego zachowania.

## Phase 2: Wspólny eksport

Rozszerzyć trwały lifecycle #61 o linked access, niezmienną tożsamość konta,
kontrolę dostępu przed zapisami, zamrożone metadata i odmowę historycznego confirm.
Wynik banku, review i powiadomień rozróżnia konto użytkownika i techniczne.
Zachować marker recovery, retry generation, dokładny wynik i świadome recreate.

## Phase 3: Granice synchronizacji

Chronić cele eksportu we wszystkich wejściach source sync i import/edit/maintenance.
Rozdzielić scopes wymagane dla private export i source sync. Zabezpieczyć unlink
między mutacjami. Zachować jeden admission i frozen input eksportu przy zmianach banku.

## Phase 4: Weryfikacja

Uruchomić pełny zestaw SQLite, PostgreSQL na jednorazowym klastrze pod /srv,
Pint, build i source contract. Naprawić wykryte regresje oraz izolację testów.
Udokumentować dowody, ograniczenia i wynik lokalnego scalenia.
Live smoke istniejących PR-ów pozostaje warunkiem wdrożenia, poza lokalnym scaleniem.

## References

- research.md — analiza kodu i macierz regresji PR #59, #61, #62.
- Użytkownik: „wykonaj scalenie”.

## Progress

### Phase 1: Połączenie historii i schematu

#### Automated

- [x] 1.1 Trzy historie połączone bez konfliktów i podwójnych schematów

### Phase 2: Wspólny eksport

#### Automated

- [x] 2.1 Linked i managed używają trwałego lifecycle z testami odzyskiwania

### Phase 3: Granice synchronizacji

#### Automated

- [x] 3.1 Source sync i eksport respektują wspólne granice oraz zakresy dostępu

### Phase 4: Weryfikacja

#### Automated

- [x] 4.1 SQLite i PostgreSQL przechodzą na scalonym kodzie
- [x] 4.2 Pint, build i source contract przechodzą
- [x] 4.3 Wynik scalenia zapisany w lokalnych commitach i dokumentacji

### Commit ledger

- Phase 1: #61 `c668166`, #59 `9cd6d7e`, #62 i rozstrzygnięcie integracji `97e1086`.
- Phase 2–3: `97e1086` — jeden eksport, source guards, import/checkpoint serialization.
- Phase 4: `97e1086` — kod i testy; wyniki w `implementation.md`, domknięte w epilogu.
- Publikacja, deploy i live smoke nie są wykonane ani oznaczone jako zakończone.
