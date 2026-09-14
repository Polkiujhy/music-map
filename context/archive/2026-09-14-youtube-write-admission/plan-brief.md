# Wspólne dopuszczenie zapisów YouTube — krótki plan

> Pełny plan: `context/changes/youtube-write-admission/plan.md`

## Co i dlaczego

F-02 tworzy jedną atomową bramkę dla wszystkich przyszłych eksportów i synchronizacji zapisujących dane do YouTube. Chroni globalny konfigurowalny limit operacji — domyślnie pięć dziennie — i gwarantuje, że retry nie zużywa kolejnego slotu ani nie rozpoczyna częściowego zapisu po odmowie.

## Punkt wyjścia

Potwierdzony `ExportReview` zamraża dokładną zawartość przyszłego eksportu, ale żaden przepływ nie zapisuje jeszcze playlist do YouTube. Obecny budżet YouTube dotyczy wyłącznie wyszukiwań katalogowych i używa disposable cache, więc nie może być trwałą księgą logicznych operacji zapisu.

## Pożądany stan końcowy

Każdy przyszły konsument przekazuje typ i własne trwałe ID operacji, a bramka zwraca nowe dopuszczenie, istniejącą rezerwację albo odmowę z momentem resetu. Rezerwacja jest committed przed pierwszym requestem YouTube, obowiązuje bezterminowo i pozostaje zużyta nawet po późniejszej awarii.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego |
| --- | --- | --- |
| Moment naliczenia | Trwały commit przed zapisem | Fail-closed chroni limit także przy awarii workera |
| Retry | Bezterminowo ta sama rezerwacja | Nie nalicza ponownie operacji po zmianie dnia |
| Tożsamość | Typ + stabilne ID konsumenta | F-02 nie zależy od nieistniejących jeszcze modeli |
| Wynik | Trzy stany + osobna awaria techniczna | Limit nie jest mylony z awarią bazy |
| Limit | Dodatni runtime, domyślnie 5 | Pozwala wykorzystać przyszłe zwiększenie kwoty |
| Zmiana limitu | Snapshot na dzień | Rolling deploy nie tworzy dwóch równoległych limitów |
| Dzień kwoty | `America/Los_Angeles` | Odpowiada resetowi YouTube i uwzględnia DST |
| Trwałość | Baza, nie cache | Retry przeżywa restart i czyszczenie cache |
| Współbieżność | Jeden globalny rekord `FOR UPDATE` | Daje jednoznaczną kolejność wszystkich konsumentów |
| Retencja | Bez usuwania admitted rows | Cleanup nie może zmienić historycznego retry w nową operację |

## Zakres

**W zakresie:** kontrakt i konfiguracja, aktualizacja NFR-006/F-02, addytywna
księga, atomowa rezerwacja, dzień PT, trwałe retry, typowane awarie, testy
SQLite i PostgreSQL oraz minimalna instrukcja integracyjna.

**Poza zakresem:** requesty YouTube, eksport, synchronizacja, UI, statusy
FR-011, konta techniczne i użytkowników, refundowanie rezerwacji, cleanup
historii oraz implementacja zewnętrznego PaaS.

## Architektura / Podejście

`typ + trwałe ID → globalny lock → istniejąca rezerwacja | reset dnia → limit
→ atomowy ledger + licznik → committed result → przyszły request YouTube`.
Osobny stan przechowuje dzień, licznik i snapshot limitu; unikalna księga
zachowuje pierwsze dopuszczenie każdego klucza na zawsze.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Polityka i kontrakt | Spójny NFR, konfigurację i typowane API | Rozjazd między limitem produktu i runtime |
| 2. Trwała rezerwacja | Addytywny schemat i atomową akcję | Zapis YouTube przed commitem albo podwójne naliczenie |
| 3. Dowód współbieżności | Wyścigi PostgreSQL i gotowość wydania | Fałszywa pewność z testów SQLite |

**Wymagania wstępne:** ukończone F-01; produkcyjny PostgreSQL; przyszli konsumenci muszą utrwalać logiczne ID przed dopuszczeniem.

**Szacowany wysiłek:** około 5–7 sesji w trzech fazach.

## Otwarte ryzyka i założenia

- Globalna blokada jest świadomym wyborem dla bardzo małego limitu; większa
  skala wymagałaby ponownej oceny, nie przedwczesnego rozproszenia licznika.
- Zmiana konfiguracji zaczyna obowiązywać od następnego dnia PT, a nie od razu.
- Cofnięcie zegara, timeout blokady i niejednoznaczny commit zatrzymują zapis;
  retry tego samego ID służy do bezpiecznego rozpoznania stanu.
- Po pierwszym użyciu rollback kodu zachowuje tabele, ponieważ usunięcie księgi
  złamałoby bezterminową idempotencję.

## Kryteria sukcesu (podsumowanie)

- Przy limicie N dokładnie N równoległych nowych operacji otrzymuje
  dopuszczenie, a każda kolejna kończy się przed pierwszą mutacją YouTube.
- Retry tego samego klucza zawsze zwraca pierwotną rezerwację i nie zmienia
  licznika, również po restarcie procesu i zmianie dnia.
- Błąd konfiguracji, bazy lub blokady daje odrębną tymczasową awarię i nigdy
  nie otwiera awaryjnej ścieżki zapisu.
