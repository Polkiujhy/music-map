# Niejednoznaczne wyniki i bezpieczne ponowienia — krótki plan

> Pełny plan: `context/changes/testing-ambiguous-outcomes-safe-retry/plan.md`
> Badania: `context/changes/testing-ambiguous-outcomes-safe-retry/research.md`

## Co i dlaczego

Faza 1 wdrożenia testów chroni atomowość, idempotencję i prawdziwość stanu wokół awarii providera. Robi to bez wymyślania wykonania eksportu: testuje istniejący import i admission, a wymagania niejednoznacznego oraz częściowego eksportu zachowuje dla przyszłych S-06/S-07.

## Punkt wyjścia

Import już buduje kompletny snapshot przed atomową zamianą, a admission ma rozbudowany pakiet PostgreSQL. Brakuje jednak testu utraty transportu pomiędzy metadanymi i elementami oraz bezpośrednio nazwanego kontrastu między wadliwym HTTP 200 a prawidłowo pustą playlistą.

## Pożądany stan końcowy

Awaria pobrania elementów nie zmienia zapisanej playlisty, kolejności pozycji ani świeżości. Kontrakt pustej playlisty jest jawny, istniejący pakiet PostgreSQL jest potwierdzony na jednorazowej bazie lub w CI, a §6 prowadzi przyszłe testy do właściwej warstwy i niezależnej wyroczni.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego | Źródło |
|---|---|---|---|
| Zakres #1/#2 | Kryteria przyszłych S-06/S-07; bez testu writera | Wykonanie eksportu nie istnieje | Badania + Plan |
| Miejsce kryteriów | Plan i wymagane wzorce §6; bez edycji roadmapy | Zgodnie z wyborem użytkownika i obowiązkowym cookbook | Plan |
| Pusta playlista | Kontrakt jednostkowy oraz osobna integracja transportu | Najtańszy pełny sygnał bez redundancji | Badania + Plan |
| HTTP fake | Tylko zewnętrzna granica Laravel HTTP | Zachowuje parsing, mapowanie, transakcję i persystencję | Badania |
| PostgreSQL | Istniejący pakiet lokalnie lub zielony job CI | SQLite nie dowodzi blokad i procesowych wyścigów | Badania + Plan |
| Wyścig admission | Nie duplikować prymitywu | Obecny pakiet obejmuje właściwe przypadki | Badania |

## Zakres

**W zakresie:**

- Kontrakt wadliwego HTTP 200 i spójnego pustego snapshotu YouTube.
- Integracyjny reimport: poprawne metadane, potem awaria transportu elementów.
- Zachowanie pełnego poprzedniego stanu i świeżości.
- Uruchomienie istniejących kontraktów, testów SQLite i PostgreSQL admission.
- Aktualizacja §6.1, §6.2, §6.4 i §6.6.

**Poza zakresem:**

- Implementacja lub testy wykonania eksportu S-06/S-07.
- Stateful probe test, live-provider smoke, E2E i nowy wyścig prymitywu.
- Nowy test konsumenta admission przed pojawieniem się writera.
- Zmiany roadmapy i szczegóły wewnętrzne Managera.

## Architektura / Podejście

Test kontraktowy zamyka semantykę odpowiedzi readera. Test Feature przechodzi przez trasę i bazę, zastępując wyłącznie zewnętrzne HTTP. PostgreSQL weryfikuje istniejący ledger tam, gdzie SQLite nie daje sygnału. Cookbook zapisuje dopiero wzorce potwierdzone przez te dowody.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
|---|---|---|
| 1. Kontrakt odpowiedzi | Wadliwy HTTP 200 ≠ prawidłowe zero | #7: fałszywie pusta playlista |
| 2. Awaria transportu | Poprzedni snapshot pozostaje identyczny | #7: częściowy/fałszywie świeży zapis |
| 3. PostgreSQL | Zachowany dowód wyścigów i ambiguous commit | #5: podwójna rezerwacja lub bypass |
| 4. Cookbook | Wzorce i przyszłe kryteria #1/#2 | Dryf przyszłych testów eksportu |

**Wymagania wstępne:** działające zależności PHP; dla lokalnej Fazy 3 `pdo_pgsql` i jednorazowa baza albo dostęp do wyniku CI.

**Szacowany wysiłek:** około jednej sesji implementacyjnej w czterech małych fazach.

## Otwarte ryzyka i założenia

- Lokalny runtime może nie mieć `pdo_pgsql`; wtedy wymagany jest wynik joba PostgreSQL w CI.
- Providerowe mechanizmy idempotencji/discovery zostaną wybrane dopiero podczas planowania S-06/S-07; brak bezpiecznego mechanizmu oznacza jawny manual-recovery.
- Pierwszy writer będzie wymagał jednego konsumenckiego wyścigu dla tej samej trwałej operacji, ale nie należy go tworzyć wcześniej.

## Kryteria sukcesu (podsumowanie)

- Awaria transportu po metadanych pozostawia playlistę, elementy i świeżość bez zmian.
- Wadliwy HTTP 200 i prawidłowa pusta playlista mają różne, jawnie przetestowane wyniki.
- Istniejący pakiet PostgreSQL przechodzi, a §6 opisuje dostarczone wzorce bez udawania, że writer już istnieje.
