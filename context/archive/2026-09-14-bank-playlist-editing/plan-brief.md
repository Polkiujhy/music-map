# Edycja playlisty w banku — krótki plan

> Pełny plan: `context/changes/bank-playlist-editing/plan.md`

## Co i dlaczego

S-03 pozwala właścicielowi przeglądać playlistę w niezależnym banku, zmieniać
kolejność istniejących wystąpień i usuwać je bez naruszania pochodzenia. Plan
celowo ogranicza zakres, aby funkcja mogła powstawać równolegle z S-05 przy
minimalnej liczbie wspólnych plików i bez eksportowania nieaktualnej treści.

## Punkt wyjścia

S-02 przechowuje do 20 uporządkowanych wystąpień, w tym duplikaty i placeholdery.
Reimport odtwarza elementy, bank nie ma edytora, a S-05 opiera review na snapshotach.

## Pożądany stan końcowy

Użytkownik przygotowuje kolejność i usunięcia, po czym zapisuje je atomowo.
Konflikt nie nadpisuje nowszego stanu; refresh chroni lokalną projekcję, a
świadomy reimport może przywrócić pełną wersję źródła.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Zakres edycji | Kolejność i usuwanie | Chroni dane providera i ogranicza kolizje z S-05 |
| Zapis | Jeden atomowy zapis szkicu | Jedna transakcja i brak częściowego stanu |
| Współbieżność | Odrzucenie starego fingerprintu | Zapobiega cichemu nadpisaniu nowszej treści |
| Aktywny review S-05 | Edycja wygrywa, review wygasa | S-03 nie zależy od modeli eksportu |
| Pusta playlista | Dozwolona po potwierdzeniu | S-02 dopuszcza zero pozycji; eksport nadal blokuje pusty manifest |
| Sterowanie kolejnością | Przyciski góra/dół | Przy limicie 20 pozycji są proste i dostępne |
| Automatyczny refresh | Uzgodnienie po `occurrence_id` | Odświeża dane bez cofania lokalnej kolejności i usunięć |
| Jawny reimport | Ostrzeżenie i pełne zastąpienie | Użytkownik świadomie wraca do wersji platformy |
| Wspólny kontrakt | Neutralny fingerprint S-03/S-05 | Eliminuje dwie różne definicje tej samej treści |

## Zakres

**W zakresie:** osobny właścicielski ekran, 0–20 wystąpień, reorder/remove,
placeholdery i duplikaty, atomowy zapis, fingerprint, konflikt wielu kart,
`bank_content_edited_at`, refresh YouTube, potwierdzony reimport i handoff S-05.

**Poza zakresem:** dodawanie i wyszukiwanie utworów, edycja danych providera,
zmiana nazwy/opisu, tworzenie lub kasowanie playlist, synchronizacja S-08,
matching i UI eksportu S-05 oraz inne platformy.

## Architektura / Podejście

`bank card → owner-scoped edit page → Livewire draft → fingerprint check →
atomic reorder/remove`. S-03 ustawia znacznik lokalnej edycji, ale nie zna tabel
S-05. Review przelicza neutralny fingerprint i wygasa po każdej zmianie treści.
Refresh YouTube wybiera pełny replacement dla nietkniętego snapshotu albo
uzgodnienie zachowanych `occurrence_id` dla lokalnej projekcji.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Kontrakt i mutacja | Fingerprint, znacznik i atomowy reorder/remove | Unique constraint i równoległy zapis |
| 2. Edytor | Właścicielska strona Livewire i minimalny link z banku | Dostępność i niejasny moment zapisu |
| 3. Refresh i reimport | Zachowanie edycji oraz świadome pełne zastąpienie | Retencja danych YouTube i utrata lokalnej pracy |
| 4. Handoff | Kontrakt S-05, pełne regresje i PostgreSQL | Semantyczny drift równoległych planów |

**Wymagania wstępne:** S-02 jest ukończone; S-05 przyjmie neutralny fingerprint
przy integracji. **Szacowany wysiłek:** około 6–9 sesji w czterech fazach.

## Otwarte ryzyka i założenia

- `occurrence_id` pozostaje stabilny między odczytami; jego brak kończy uzgodnienie bez zapisu.
- Refresh nie dopisuje nowych pozycji ze źródła; zrobi to reimport albo przyszłe S-08.
- Nieudany refresh aż do 30 dni nadal prowadzi do istniejącego purge danych.
- Wspólne hot files scala addytywnie jeden integration owner; S-03 nie zmienia `BankController`.

## Kryteria sukcesu (podsumowanie)

- Jeden zapis nie traci duplikatów, placeholderów ani pochodzenia.
- Druga karta, refresh, reimport i review S-05 nie powodują silent lost update ani
  częściowej mutacji.
- Automatyczny refresh chroni lokalną projekcję, jawny reimport jasno ostrzega o
  zastąpieniu, a cały przepływ działa dostępnie dla 20 pozycji.
