# Eksport na konto techniczne music-map — krótki plan

> Pełny plan: `context/changes/managed-account-export/plan.md`

## Co i dlaczego

S-06 pozwala użytkownikowi bez powiązanego konta przenieść zatwierdzony manifest na techniczne konto Spotify albo YouTube. Wynik ma stały link, jasno wskazanego właściciela i bezpieczne ponowienie tej samej częściowo zapisanej playlisty.

## Punkt wyjścia

S-05 zamraża dokładny `ConfirmedExportManifest`, a F-02 atomowo rezerwuje logiczne zapisy YouTube. Brakuje jednak trwałej operacji, relacji do zewnętrznej kopii, produktowych gatewayów zapisu i statusów FR-011.

## Pożądany stan końcowy

Jedno świadome potwierdzenie uruchamia eksport w tle. Spotify tworzy cel z
`public=false`, YouTube z `unlisted`; aplikacja zapisuje provider ID przed
pozycjami i doprowadza zawartość do dokładnego manifestu. Review i bank pokazują
status, właściciela, instrukcję i stały link, a retry zachowuje ten sam cel.
Dopiero stabilny sukces materializuje osobny docelowy snapshot `Playlist`
powiązany ze źródłem; częściowa kopia pozostaje wyłącznie locatorem.

## Kluczowe podjęte decyzje

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Platformy | Spotify i YouTube | S-06 dostarcza pełny FR-010 | Plan |
| Start | Potwierdzenie od razu tworzy operację | PRD wiąże świadome potwierdzenie ze startem | Plan |
| Tożsamość kopii | Jedna na źródło+provider+konto | Zapobiega duplikatom i wspiera przyszły drift | Plan |
| Nieznany create | Marker + read-only recovery | Kolejny create mógłby utworzyć duplikat | Badania / Plan |
| Historia create | Osobny rekord każdej generacji markera | Recreate nie może usunąć śladu możliwej osieroconej playlisty | Plan review |
| Partial retry | Exact-state reconciliation | Zachowuje kolejność, duplikaty i ten sam link | Plan |
| Współbieżność | Jeden aktywny eksport celu | Dwa manifesty nie ścigają się o jedną kopię | Plan |
| Retry | Trzy trwałe automatyczne claimy na retry generation | Recovery nie resetuje limitu ani nie namnaża aktywnych jobów | Plan review |
| Widoczność | Spotify private-profile, YouTube unlisted | Cel jest dostępny linkiem i niewidoczny na profilu | Badania / Plan |
| Utracony cel | Jawne odtworzenie | Nowy link nigdy nie powstaje ukradkiem | Plan |
| Status | Review i bounded karta banku | Użytkownik ma bieżący i trwały punkt powrotu | Plan |
| Metadane | Nazwa bankowa + managed opis + marker | Łączy rozpoznawalność, własność i recovery | Plan |
| Akceptacja | Fake CI + PostgreSQL + live smoke | API nie gwarantują wszystkich zachowań markera | Badania / Plan |

## Zakres

**W zakresie:** oba konta techniczne, trwała relacja, historia prób celu,
operacja i docelowy snapshot po sukcesie, admission F-02, gatewaye, marker
recovery, exact reconciliation, statusy FR-011, retry/recreate, polling, bank,
powiadomienie, recovery dispatchu, PostgreSQL i live smoke.

**Poza zakresem:** linked export S-07, synchronizacja i drift S-08/S-09,
usuwanie konta S-10, wyszukiwanie po tytule, playlisty ponad 20 pozycji, realne
API w CI oraz internals zewnętrznego PaaS.

## Architektura / Podejście

`confirmed review → ExportOperation → F-02 dla YouTube → technical access →
create lub marker recovery → commit target attempt → exact reconcile →
materializacja docelowego Playlist → status`. `PlaylistExport` jest kanoniczną
relacją źródło–zewnętrzna kopia, `PlaylistExportTargetAttempt` zachowuje każdą
generację markera, a operacja pełni także rolę durable outboxu odzyskiwanego
przez scheduler.

## Fazy w skrócie

| Faza | Co dostarcza | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Trwały kontrakt | Relację, historię prób celu, operację i constrainty | Utrata locatora przez zły cascade |
| 2. Gatewaye | Bezpieczny access i oba provider contracts | Marker/visibility/rotacja tokenu |
| 3. Wykonanie | Atomowy start, checkpoint, reconcile i snapshot sukcesu | Niejednoznaczny create i wyścigi |
| 4. UX i retry | Status, retry/recreate, mail i recovery jobów | Drugi start lub duplikacja powiadomień |
| 5. Dowód | Fault matrix, PostgreSQL i live smoke | Różnica fake–rzeczywiste API |

**Wymagania wstępne:** ukończone S-05 i F-02; techniczne konta z prawami
create/update; publiczny, wersjonowany kontrakt rotacji technical refresh tokenu
dla zwykłych workerów jako oczekująca zależność zewnętrzna. Produkcyjna
integracja Fazy 2 czeka na jego publikację oraz wpisanie do pełnego planu
dokładnej wersji i trwałego publicznego źródła. Bez niego worker działa
fail-closed przed użyciem access tokenu, a S-06 nie jest gotowe do wydania.

**Szacowany wysiłek:** około 12–18 sesji w pięciu fazach.

## Otwarte ryzyka i założenia

- Spotify może zwrócić `null` zamiast opisu; brak jednoznacznego markera kończy się ręcznym odzyskaniem, nigdy drugim automatycznym create.
- Providerzy nie oferują udokumentowanego idempotency key dla create playlisty.
- Publiczny, wersjonowany kontrakt rotacji refresh tokenu dla zwykłych workerów
  jest oczekującą zależnością zewnętrzną; produkcyjna integracja czeka na jego
  publikację, a wcześniej aplikacja kończy operację fail-closed.
- Jawne recreate może zmienić link i powstaje dopiero po akceptacji użytkownika.

## Kryteria sukcesu (podsumowanie)

- Użytkownik eksportuje zatwierdzony manifest na oba techniczne konta i widzi właściciela, instrukcję oraz stały link.
- Awaria częściowa i retry zachowują operation ID, provider ID oraz rezerwację F-02 i kończą się dokładną kolejnością bez duplikatu playlisty.
- Wyścigi PostgreSQL, utrata enqueue i niejednoznaczne odpowiedzi nie obchodzą admission ani zakazu drugiego create; logi i artefakty nie ujawniają sekretów.
