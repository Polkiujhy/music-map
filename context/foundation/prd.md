---
project: "music-map"
version: 1
status: draft
created: 2026-08-28
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: 2026-09-14
  hard_deadline_time: "23:59 Europe/London"
  after_hours_only: true
---

## Vision & Problem Statement

Autor pomysłu i jego znajomi potrzebują niezależnego banku playlist, ponieważ podczas zmiany między dwiema platformami streamingowymi playlisty pozostają uwięzione na jednej platformie, a obecne konwertery nie przechowują ich jako trwałego źródła. Powoduje to ręczne odtwarzanie kolekcji, stratę czasu i zależność od dostawcy.

Aplikacja ma przechowywać playlisty niezależnie oraz pozwalać eksportować je na wybrane konto platformy. Kluczowa różnica względem jednorazowego konwertera polega na tym, że bank playlist pozostaje źródłem niezależnym od bieżącej subskrypcji użytkownika.

## User & Persona

Główną personą jest autor pomysłu oraz jego znajomi: osoby aktywnie zarządzające własnymi playlistami, które zmieniają usługę między dwiema platformami streamingowymi i chcą uniknąć ponownego ręcznego budowania kolekcji.

## Success Criteria

### Primary

- Użytkownik odwiedza publiczną stronę, zakłada konto i importuje playlistę z linku udostępniania do niezależnego banku `music-map`.
- Użytkownik może opcjonalnie edytować playlistę, wybrać jedną z dwóch obsługiwanych platform streamingowych oraz zobaczyć dopasowane i niedostępne utwory przed eksportem.
- Eksport rozpoczyna się dopiero po świadomym potwierdzeniu użytkownika.
- Przy powiązanym koncie playlista powstaje lub jest aktualizowana na koncie użytkownika ze statusem `Przeniesiona — na Twoim koncie`.
- Bez powiązanego konta playlista powstaje lub jest aktualizowana na koncie technicznym aplikacji, a użytkownik otrzymuje link, instrukcję i status `Przeniesiona — zarządzana przez music-map`.
- Operacja w toku ma status `W trakcie przenoszenia`, a nieudana operacja ma status `Nie przeniesiono` wraz z przyczyną.

### Secondary

- Ręczne tworzenie nowej playlisty od zera w banku `music-map`.
- Wizualna mapa muzyki i relacji między playlistami.
- Ręczny wybór zamiennika dla niedostępnego lub błędnie dopasowanego utworu po przejściu do powiązanego artysty i jego utworów z zewnętrznego katalogu relacji artystów i popularności utworów.

### Guardrails

- Użytkownik zawsze widzi niedostępne lub niedopasowane utwory przed rozpoczęciem eksportu.
- Wynik jasno informuje, kto jest właścicielem playlisty docelowej i gdzie można ją edytować.
- Jeżeli `music-map` zna ID istniejącej playlisty na danej platformie i użytkownik ma prawo ją zmieniać, aktualizuje ją zamiast tworzyć nową.

Zakres MVP ma zostać zrealizowany w ciągu trzech tygodni pracy po godzinach.

## User Stories

### US-01: Eksport playlisty

- **Given** zalogowany użytkownik ma playlistę w banku i wybiera inną platformę docelową.
- **When** użytkownik przegląda wynik sprawdzania dostępności i świadomie potwierdza eksport.
- **Then** `music-map` aktualizuje playlistę docelową po zapisanym ID albo tworzy nową, zapisuje ją jako osobną playlistę powiązaną ze źródłem oraz zwraca status i link z informacją o właścicielu.

### US-02: Synchronizacja źródła

- **Given** zaimportowana playlista należy do powiązanego konta użytkownika.
- **When** użytkownik włącza automatyczną synchronizację albo uruchamia ją ręcznie.
- **Then** zmiany wykonane w `music-map` aktualizują źródło po zapisanym ID, a zmiany z platformy aktualizują bank bez tworzenia nowej playlisty; w konflikcie wygrywa platforma źródłowa.

## Functional Requirements

### Konto i bank playlist

- FR-001: Użytkownik może utworzyć konto `music-map` i zalogować się przez e-mail lub zewnętrznego dostawcę tożsamości. Priority: must-have
  > Sokrates: Rozważono ryzyko utworzenia dwóch kont przez logowanie e-mailem i przez zewnętrznego dostawcę tożsamości z tym samym adresem. Rozwiązanie: jeden adres e-mail odpowiada jednemu kontu `music-map`, a metody logowania zostają powiązane.
- FR-002: Zalogowany użytkownik może przeglądać playlisty zapisane w swoim niezależnym banku `music-map` oraz relacje źródło–eksport między powiązanymi playlistami z różnych platform. Priority: must-have
  > Sokrates: Rozważono niejasną widoczność playlist przechowywanych w banku. Rozwiązanie: playlistę widzi wyłącznie jej właściciel.
- FR-003: Zalogowany użytkownik może utworzyć nową playlistę od zera w banku `music-map`. Priority: nice-to-have
  > Sokrates: Rozważono, że ręczne tworzenie i walidacja pustych playlist rozszerzają pierwszy przepływ ponad konieczny import i eksport. Rozwiązanie: tworzenie playlisty od zera przeniesiono do funkcji `miło-mieć`.
- FR-004: Zalogowany użytkownik może zaimportować do banku `music-map` playlistę z linku udostępniania jednej z obsługiwanych platform streamingowych, jeżeli platforma pozwala odczytać jej zawartość. Publiczna playlista YouTube może być odczytana bez powiązania konta; zawartość playlisty Spotify wymaga powiązanego konta, które jest jej właścicielem lub współpracownikiem. W pozostałych przypadkach użytkownik otrzymuje przyczynę odmowy oraz wskazanie, aby zmienić widoczność playlisty albo powiązać właściwe konto źródłowe. Priority: must-have
  > Sokrates: Rozważono, że share link nie omija ustawień prywatności platformy i może nie pozwolić na odczyt playlisty. Rozwiązanie: import jest wykonywany tylko dla dostępnej zawartości; przy odmowie użytkownik widzi przyczynę oraz wskazanie zmiany widoczności lub powiązania konta.
- FR-005: Zalogowany użytkownik może edytować playlistę przechowywaną w banku `music-map`. Jeżeli importowana playlista źródłowa należy do jego powiązanego konta platformy, może włączyć dwukierunkową automatyczną synchronizację albo uruchomić ją ręcznie. Zmiany w banku aktualizują źródło po zapisanym ID, a zmiany wykryte na platformie aktualizują bank; przy wyłączonej synchronizacji automatycznej zmiany zewnętrzne są ignorowane do czasu użycia przycisku. W razie konfliktu wersja platformy zewnętrznej jest nadrzędna. Priority: must-have
  > Sokrates: Rozważono, że edycja niezależnej kopii może rozminąć ją ze źródłem albo tworzyć zbędne kopie. Rozwiązanie: użytkownik wybiera synchronizację automatyczną lub ręczną, a aktualizacja używa zapisanego ID istniejącej playlisty.

### Integracje i eksport

- FR-006: Zalogowany użytkownik może powiązać lub odłączyć swoje konto jednej z obsługiwanych platform streamingowych od konta `music-map`; odłączenie konta automatycznie wyłącza synchronizację wszystkich playlist powiązanych z tym kontem. Priority: must-have
  > Sokrates: Rozważono pozostawienie aktywnej synchronizacji mimo odłączenia konta platformy. Rozwiązanie: odłączenie konta automatycznie wyłącza synchronizację wszystkich powiązanych z nim playlist.
- FR-007: Użytkownik może wybrać jedną z dwóch obsługiwanych platform streamingowych jako cel eksportu playlisty, z wyjątkiem tej samej pary platforma–konto, z której pochodzi playlista źródłowa. Priority: must-have
  > Sokrates: Rozważono, że eksport do dokładnie tego samego źródła nie wnosi wartości i powiela synchronizację. Rozwiązanie: ta sama para platforma–konto nie jest dostępna jako cel eksportu.
- FR-008: Użytkownik może przed eksportem zobaczyć dopasowane, błędnie dopasowane i niedostępne utwory. Brakującą pozycję może usunąć albo pozostawić w banku, przy czym nie trafia ona do eksportu. Błędne dopasowanie może usunąć albo pozostawić, a pozostawione trafia do eksportu jako wskazany utwór docelowy. Następnie użytkownik świadomie potwierdza rozpoczęcie operacji. Priority: must-have
  > Sokrates: Rozważono pomieszanie utworów nieistniejących z dopasowanymi do niewłaściwej piosenki. Rozwiązanie: brakujący utwór jest pomijany, a pozostawione błędne dopasowanie eksportuje się jako pokazany utwór docelowy; ręczny zamiennik pozostaje `miło-mieć`.
- FR-009: Użytkownik z powiązanym kontem platformy może utworzyć eksportowaną playlistę bezpośrednio na swoim koncie albo zaktualizować jej wcześniej zapisaną kopię, rozpoznawaną wyłącznie po ID playlisty nadanym przez platformę. Nowa playlista nie jest widoczna publicznie na profilu; na platformie wideo jest dostępna przez link, a na platformie audio pozostaje prywatna. Priority: must-have
  > Sokrates: Rozważono niepotrzebne ujawnienie kolekcji przez nieokreśloną widoczność. Rozwiązanie: playlista na powiązanym koncie nie jest widoczna publicznie na profilu; na platformie wideo jest dostępna przez link, a na platformie audio pozostaje prywatna.
- FR-010: Użytkownik bez powiązanego konta może otrzymać playlistę utworzoną na koncie technicznym `music-map` albo zaktualizować jej wcześniej zapisaną kopię, rozpoznawaną wyłącznie po ID playlisty nadanym przez platformę. Playlista jest dostępna przez stały link i niewidoczna publicznie na profilu, zgodnie z możliwościami platformy. Wynik zawiera link, instrukcję, informację o właścicielu i miejscu edycji. Priority: must-have
  > Sokrates: Rozważono, że jedna z platform nie obsługuje automatycznego generowania czasowych zaproszeń. Rozwiązanie: użytkownik otrzymuje stały link, a playlista pozostaje niewidoczna publicznie na profilu zgodnie z możliwościami platformy.
- FR-011: Użytkownik może zobaczyć status eksportu jako `W trakcie przenoszenia`, `Przeniesiona — na Twoim koncie`, `Przeniesiona — zarządzana przez music-map`, `Nie przeniesiono` albo `Nie udało się dokończyć przenoszenia`, wraz z przyczyną niepowodzenia. Gdy platforma przerwie rozpoczętą operację, użytkownik otrzymuje komunikat: „Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii” i może ponowić eksport. Priority: must-have
  > Sokrates: Rozważono przerwanie eksportu po dodaniu tylko części utworów. Rozwiązanie: użytkownik widzi status `Nie udało się dokończyć przenoszenia`, przyczynę i możliwość ponowienia; kolejna próba aktualizuje tę samą playlistę po ID.

### Funkcje dodatkowe

- FR-012: Użytkownik może zobaczyć wizualną mapę przedstawiającą autorów oraz powiązane z nimi utwory. Priority: nice-to-have
  > Sokrates: Rozważono, że pojęcie „mapy muzyki” nie określa relacji do pokazania. Rozwiązanie: pierwszy wariant mapy przedstawia autorów oraz powiązane z nimi utwory.
- FR-013: Użytkownik może przy niedostępnym lub błędnie dopasowanym utworze zobaczyć sugestię „Sprawdź też artystę X”, przejrzeć popularne utwory powiązanego artysty pochodzące z zewnętrznego katalogu relacji artystów i popularności utworów oraz ręcznie wybrać zamiennik. Priority: nice-to-have
  > Sokrates: Rozważono zatarcie pochodzenia rekomendacji przez mieszanie źródeł danych. Rozwiązanie: rekomendacje używają wyłącznie powiązanych artystów i ich popularnych utworów z jednego zewnętrznego katalogu, a dane źródłowe pozostają tylko do odczytu.

### Spójność i cykl życia

- FR-014: Po synchronizacji playlisty system porównuje ją z powiązanymi playlistami źródło–eksport. Jeżeli wykryje różnice po drugiej stronie, oznacza odpowiednią playlistę jako `Nieaktualna`, powiadamia użytkownika komunikatem wskazującym platformę zmiany i pozwala otworzyć widok różnic. Z widoku różnic użytkownik może ponowić eksport ze źródła; system aktualizuje powiązaną playlistę po zapisanym ID, a po sukcesie oznacza obie strony jako aktualne. Priority: must-have
  > Sokrates: Rozważono, że samo pokazanie różnic nie przywraca zgodności. Rozwiązanie: użytkownik ponawia eksport do nieaktualnej playlisty, która jest aktualizowana po zapisanym ID; po sukcesie obie strony są aktualne.
- FR-015: Zalogowany użytkownik może usunąć konto `music-map` po zobaczeniu listy skutków i wyraźnym potwierdzeniu. Operacja usuwa jego bank playlist, relacje, historię synchronizacji, dane umożliwiające dostęp do powiązanych usług i playlisty zarządzane na koncie technicznym `music-map`, ale pozostawia playlisty utworzone na jego powiązanych kontach platform streamingowych. Priority: must-have
  > Sokrates: Rozważono ryzyko usunięcia playlist należących do użytkownika albo pozostawienia jego danych na koncie technicznym. Rozwiązanie: `music-map` usuwa własne dane i zarządzane kopie oraz unieważnia integracje, ale pozostawia playlisty na kontach użytkownika.

## Non-Functional Requirements

- NFR-001 — Pojemność: MVP niezawodnie obsługuje playlisty zawierające do 20 utworów w całym przepływie importu, sprawdzania dostępności, synchronizacji i eksportu.
- NFR-002 — Responsywność: dla playlisty do 20 utworów celem jest przygotowanie podglądu dostępności i dopasowania w około 30 sekund, a aktywne oczekiwanie użytkownika nie przekracza 60 sekund. Po przekroczeniu minuty użytkownik może opuścić ekran bez anulowania operacji i otrzymuje powiadomienie po jej zakończeniu.
- NFR-003 — Bezpieczeństwo integracji: dane uwierzytelniające powiązanych platform streamingowych pozostają poufne, nie pojawiają się w logach dostępnych operatorowi, nie przyznają aplikacji uprawnień wykraczających poza jej funkcje i przestają umożliwiać dostęp po odłączeniu integracji lub usunięciu konta.
- NFR-004 — Świeżość synchronizacji: przy włączonej synchronizacji zmiana na platformie jest wykrywana najpóźniej w ciągu 4 godzin. Logowanie może uruchomić dodatkową kontrolę, jeżeli od poprzedniej minęło co najmniej 15 minut, a użytkownik może niezależnie zażądać synchronizacji ręcznej.
- NFR-005 — Odtwarzalność danych: maksymalna dopuszczalna utrata zmian w banku playlist wynosi 24 godziny, a usługa i dane powinny zostać odtworzone w ciągu 24 godzin.
- NFR-006 — Budżet YouTube: MVP pozwala globalnie rozpocząć najwyżej pięć operacji eksportu lub synchronizacji zapisujących dane w YouTube podczas jednego dnia rozliczeniowego kwoty API. Limit jest wspólny dla wszystkich użytkowników; jego wyczerpanie nie rozpoczyna częściowej operacji i zwraca czytelną informację o czasowej niedostępności.

## Business Logic

`music-map` traktuje każdą playlistę platformową jako osobny byt z jednym nadrzędnym źródłem, wykrywa różnice między powiązanymi kopiami i przywraca ich zgodność przez ponowny eksport do playlisty wskazanej zapisanym ID.

- BR-001 — Zmiany zewnętrzne playlisty źródłowej są pobierane automatycznie tylko przy włączonej synchronizacji. Przy wyłączonej synchronizacji wymagają użycia przycisku; bez akcji użytkownika `music-map` je ignoruje.
- BR-002 — W konflikcie między zmianami w banku `music-map` a zmianami na platformie źródłowej danej playlisty nadrzędna jest wersja tej platformy, która aktualizuje kopię przechowywaną w banku.
- BR-003 — Każda playlista ma jedno nadrzędne źródło i własne ID nadane przez platformę. Playlisty utworzone na różnych platformach są osobnymi bytami połączonymi relacją źródło–eksport, nawet gdy zawierają identyczny zestaw utworów; relacja służy do nawigacji i porównywania różnic.
- BR-004 — Nieaktualna playlista powiązana odzyskuje zgodność przez ponowny eksport z jej źródła i aktualizację po zapisanym ID, bez tworzenia nowej playlisty i bez osobnego scalania.
- BR-005 — Usunięcie konta `music-map` nie usuwa playlist należących do użytkownika na powiązanych platformach streamingowych. Usuwa natomiast dane aplikacji, dane umożliwiające dostęp do powiązanych usług oraz playlisty utworzone dla niego na koncie technicznym `music-map`, po uprzednim pokazaniu skutków i uzyskaniu wyraźnego potwierdzenia.

## Access Control

`music-map` ma publiczną stronę prezentującą produkt, ale bank playlist i pozostałe funkcje są dostępne wyłącznie po zalogowaniu. Użytkownik tworzy osobne konto aplikacji przez e-mail lub zewnętrznego dostawcę tożsamości. Może opcjonalnie powiązać konta dwóch obsługiwanych platform streamingowych i kolejnych usług muzycznych. Wszyscy zalogowani użytkownicy mają takie same uprawnienia.

## Non-Goals

- Ręczne tworzenie playlist od zera.
- Wizualna mapa autorów i ich utworów.
- Zamienniki oparte na zewnętrznym katalogu relacji artystów i popularności utworów.
- Obsługa innych platform muzycznych niż dwie objęte zakresem MVP.
- Gwarantowana obsługa playlist zawierających więcej niż 20 utworów.
- Udostępnianie banku playlist innym użytkownikom i rozbudowane role.
- Jednorazowe wzbogacenie zewnętrznych danych o daty premier.
- Paywall, subskrypcje i rozliczenia.

## Open Questions

Brak otwartych pytań blokujących przygotowanie PRD.
