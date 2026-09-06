---
project: "music-map"
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
created: 2026-08-28
updated: 2026-08-28
timeline_budget:
  mvp_weeks: 3
  hard_deadline: 2026-09-14
  after_hours_only: true
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "typ kontekstu"
      decision: "greenfield — aplikacja powstaje od zera; istniejąca infrastruktura danych jest kwestią do późniejszego rozpatrzenia"
    - topic: "główna persona"
      decision: "autor pomysłu i jego znajomi, którzy zarządzają własnymi playlistami i zmieniają platformę streamingową"
    - topic: "kategoria bólu"
      decision: "dane uwięzione na platformie oraz tarcie podczas zmiany usługi"
    - topic: "rola aplikacji"
      decision: "niezależny bank playlist będący trwałym źródłem, z którego playlisty można eksportować na wybraną platformę"
    - topic: "dostęp do aplikacji"
      decision: "publiczna prezentacja produktu; funkcje banku playlist dostępne tylko po zalogowaniu"
    - topic: "konto i logowanie"
      decision: "osobne konto music-map przez e-mail lub zewnętrznego dostawcę tożsamości; konta usług muzycznych są opcjonalnie powiązywane"
    - topic: "tożsamość konta przy różnych metodach logowania"
      decision: "jeden adres e-mail odpowiada jednemu kontu music-map; logowanie e-mailem i przez zewnętrznego dostawcę tożsamości z tym samym adresem zostają powiązane"
    - topic: "role użytkowników"
      decision: "płaski model — wszyscy zalogowani użytkownicy mają równe uprawnienia"
    - topic: "widoczność playlist w banku"
      decision: "playlista zapisana w banku music-map jest widoczna wyłącznie dla jej właściciela"
    - topic: "niedostępny link źródłowy"
      decision: "import przez link działa tylko dla playlist, których zawartość platforma pozwala odczytać; przy odmowie music-map pokazuje przyczynę i prosi o zmianę widoczności albo powiązanie konta źródłowego"
    - topic: "zakres głównego przepływu MVP"
      decision: "bank playlist, import z linku udostępniania, edycja, kontrola dostępności przed potwierdzeniem oraz eksport do jednej z dwóch obsługiwanych platform streamingowych; ręczne tworzenie playlisty od zera pozostaje funkcją dodatkową"
    - topic: "własność playlisty docelowej"
      decision: "na powiązanym koncie użytkownika albo na koncie technicznym music-map z linkiem, instrukcją i jawnym statusem"
    - topic: "status eksportu bez powiązanego konta"
      decision: "Przeniesiona — zarządzana przez music-map"
    - topic: "kontrola niedostępnych utworów"
      decision: "lista dostępnych i niedostępnych utworów jest pokazywana przed eksportem; użytkownik musi świadomie potwierdzić operację"
    - topic: "brakujący i błędnie dopasowany utwór przed eksportem"
      decision: "w MVP brakujący utwór można usunąć albo pozostawić w banku, lecz nie trafia on do eksportu; błędnie dopasowany utwór można usunąć albo pozostawić, a pozostawione dopasowanie trafia do eksportu; ręczny wybór innego utworu jest funkcją dodatkową"
    - topic: "rozpoznawanie istniejącej playlisty docelowej"
      decision: "music-map zapisuje ID playlisty nadane przez platformę i tylko po tym ID odnajduje ją przy kolejnym eksporcie; nie wyszukuje playlist po tytule"
    - topic: "synchronizacja playlisty źródłowej"
      decision: "music-map sprawdza właściciela importowanej playlisty; gdy należy ona do powiązanego konta użytkownika, synchronizacja może działać dwukierunkowo: zmiany w banku trafiają do źródła, a zmiany wykryte na platformie aktualizują bank; przy wyłączonej automatycznej synchronizacji operację uruchamia osobny przycisk, a bez akcji użytkownika zmiany zewnętrzne są ignorowane"
    - topic: "odłączenie konta platformy"
      decision: "odłączenie konta jednej z obsługiwanych platform streamingowych automatycznie wyłącza synchronizację playlist powiązanych z tym kontem"
    - topic: "konflikt zmian podczas synchronizacji"
      decision: "gdy od ostatniej synchronizacji zmieniła się zarówno playlista w music-map, jak i jej wersja na platformie zewnętrznej, wersja platformy jest nadrzędna i aktualizuje kopię w banku"
    - topic: "tożsamość playlist na różnych platformach"
      decision: "każda playlista pochodząca z platformy jest osobnym bytem z jednym nadrzędnym źródłem i własnym ID platformy; import z jednej platformy i eksport jego utworów do drugiej tworzą dwie różne playlisty połączone relacją źródło–eksport, która ułatwia nawigację i pozwala porównywać ich zawartość"
    - topic: "eksport do źródła"
      decision: "platforma i konto, z których pochodzi playlista źródłowa, nie mogą być jednocześnie wybrane jako cel jej eksportu"
    - topic: "widoczność playlisty na powiązanym koncie"
      decision: "nowa playlista tworzona na powiązanym koncie użytkownika nie jest widoczna publicznie na profilu; na platformie wideo jest dostępna przez link, a na platformie audio pozostaje prywatna"
    - topic: "widoczność playlisty na koncie technicznym"
      decision: "playlista zarządzana przez music-map ma być dostępna przez stały link i niewidoczna publicznie na profilu, zgodnie z możliwościami platformy; jedna z platform nie obsługuje automatycznego generowania czasowych zaproszeń"
    - topic: "przerwanie eksportu w trakcie"
      decision: "użytkownik widzi status Nie udało się dokończyć przenoszenia oraz komunikat, aby spróbować ponownie za kilka minut; ponowienie aktualizuje tę samą playlistę po zapisanym ID i nie tworzy kolejnej kopii"
    - topic: "funkcje dodatkowe"
      decision: "wizualna mapa relacji oraz uzupełnianie playlist podobnymi, dopasowanymi utworami z bazy"
    - topic: "pierwszy zakres mapy muzyki"
      decision: "mapa przedstawia powiązania między autorami i należącymi do nich utworami, wykorzystując dostępne dane"
    - topic: "źródło rekomendacji artystów i utworów"
      decision: "music-map używa wyłącznie zewnętrznego katalogu relacji artystów i popularności utworów; nie używa lokalnych przejść odsłuchów ani alternatywnego katalogu relacji muzycznych do rekomendacji i nie modyfikuje danych źródłowych"
    - topic: "popularne a najnowsze utwory"
      decision: "interfejs opisuje obecne dane jako popularne utwory artysty; jednorazowe uzupełnienie danych o premierach pozostaje poza odpowiedzialnością repozytorium music-map"
    - topic: "nieaktualna powiązana playlista"
      decision: "po synchronizacji jednej playlisty music-map porównuje powiązane playlisty; gdy występują różnice, oznacza drugą stronę jako nieaktualną i powiadamia użytkownika, na której platformie zaszły zmiany"
    - topic: "aktualizacja powiązanej playlisty"
      decision: "z widoku różnic użytkownik ponawia eksport ze źródła do nieaktualnej playlisty; system aktualizuje ją po zapisanym ID, a po sukcesie obie strony relacji są aktualne"
    - topic: "maksymalny rozmiar playlisty w MVP"
      decision: "import, sprawdzanie, synchronizacja i eksport niezawodnie obsługują playlisty do 50 utworów"
    - topic: "czas sprawdzania playlisty"
      decision: "dla 50 utworów celem jest wynik w około 30 sekund i najwyżej 60 sekund aktywnego oczekiwania; po minucie operacja działa dalej w tle, a użytkownik otrzymuje powiadomienie po zakończeniu"
    - topic: "usunięcie konta music-map"
      decision: "po wyraźnym potwierdzeniu usuwane są dane banku, relacje, historia synchronizacji, integracje i playlisty na koncie technicznym music-map; playlisty utworzone na powiązanych kontach użytkownika pozostają na platformach"
    - topic: "bezpieczeństwo integracji platform"
      decision: "dane uwierzytelniające integracji pozostają poufne, nie trafiają do logów, zapewniają tylko minimalny zakres dostępu i przestają umożliwiać dostęp po odłączeniu integracji lub usunięciu konta"
    - topic: "częstotliwość automatycznej synchronizacji"
      decision: "playlisty z aktywną synchronizacją są sprawdzane co 4 godziny oraz po zalogowaniu, jeśli od ostatniej kontroli minęło co najmniej 15 minut; ręczna synchronizacja jest dostępna niezależnie od harmonogramu"
    - topic: "odtwarzalność banku playlist"
      decision: "baza ma codzienny szyfrowany backup; maksymalna utrata zmian i czas odtworzenia wynoszą po 24 godziny"
    - topic: "cele niezwiązane z MVP"
      decision: "MVP nie obejmuje mapy muzyki, propozycji utworów do playlist ani budowania playlist od zera"
    - topic: "wpływ 100-krotnie większej skali"
      decision: "przy większej skali synchronizacja byłaby uruchamiana na żądanie i dostępna za paywallem; płatności nie należą do MVP"
    - topic: "twardy termin MVP"
      decision: "14 września 2026 do 23:59"
  frs_drafted: 15
  quality_check_status: accepted
---

# Notatki kształtowania

## Pomysł początkowy

pomysł na repo jest taki apka webowa z mapą muzyki i powiązaniami pomiędzy nią, pozwala tworzyć importować i eksportować
  playlisty pomiędzy dwiema platformami streamingowymi z wnętrza apki; istniejąca infrastruktura danych jest do późniejszego rozpatrzenia

## Wizja i Oświadczenie o Problemie

Autor pomysłu i jego znajomi potrzebują niezależnego banku playlist, ponieważ podczas zmiany między dwiema platformami streamingowymi playlisty pozostają uwięzione na jednej platformie, a obecne konwertery nie przechowują ich jako trwałego źródła. Powoduje to ręczne odtwarzanie kolekcji, stratę czasu i zależność od dostawcy.

Aplikacja ma przechowywać playlisty niezależnie oraz pozwalać eksportować je na wybrane konto platformy. Kluczowa różnica względem jednorazowego konwertera polega na tym, że bank playlist pozostaje źródłem niezależnym od bieżącej subskrypcji użytkownika.

Przy około 100-krotnie większej bazie użytkowników synchronizacja byłaby uruchamiana na żądanie i mogłaby zostać objęta paywallem, aby ograniczyć koszt zewnętrznych API.

## Użytkownik i Persona

Główną personą jest autor pomysłu oraz jego znajomi: osoby aktywnie zarządzające własnymi playlistami, które zmieniają usługę między dwiema platformami streamingowymi i chcą uniknąć ponownego ręcznego budowania kolekcji.

## Kryteria Sukcesu

### Podstawowe

- Użytkownik odwiedza publiczną stronę, zakłada konto i importuje playlistę z linku udostępniania do niezależnego banku `music-map`.
- Użytkownik może opcjonalnie edytować playlistę, wybrać jedną z dwóch obsługiwanych platform streamingowych oraz zobaczyć dopasowane i niedostępne utwory przed eksportem.
- Eksport rozpoczyna się dopiero po świadomym potwierdzeniu użytkownika.
- Przy powiązanym koncie playlista powstaje lub jest aktualizowana na koncie użytkownika ze statusem `Przeniesiona — na Twoim koncie`.
- Bez powiązanego konta playlista powstaje lub jest aktualizowana na koncie technicznym aplikacji, a użytkownik otrzymuje link, instrukcję i status `Przeniesiona — zarządzana przez music-map`.
- Operacja w toku ma status `W trakcie przenoszenia`, a nieudana operacja ma status `Nie przeniesiono` wraz z przyczyną.

### Dodatkowe

- Ręczne tworzenie nowej playlisty od zera w banku `music-map`.
- Wizualna mapa muzyki i relacji między playlistami.
- Ręczny wybór zamiennika dla niedostępnego lub błędnie dopasowanego utworu po przejściu do powiązanego artysty i jego utworów z zewnętrznego katalogu relacji artystów i popularności utworów.

### Bariery ochronne

- Użytkownik zawsze widzi niedostępne lub niedopasowane utwory przed rozpoczęciem eksportu.
- Wynik jasno informuje, kto jest właścicielem playlisty docelowej i gdzie można ją edytować.
- Jeżeli `music-map` zna ID istniejącej playlisty na danej platformie i użytkownik ma prawo ją zmieniać, aktualizuje ją zamiast tworzyć nową.

Zakres MVP ma zostać zrealizowany w ciągu trzech tygodni pracy po godzinach.

## Historie Użytkowników

### US-01: Eksport playlisty

- **Given** zalogowany użytkownik ma playlistę w banku i wybiera inną platformę docelową.
- **When** użytkownik przegląda wynik sprawdzania dostępności i świadomie potwierdza eksport.
- **Then** `music-map` aktualizuje playlistę docelową po zapisanym ID albo tworzy nową, zapisuje ją jako osobną playlistę powiązaną ze źródłem oraz zwraca status i link z informacją o właścicielu.

### US-02: Synchronizacja źródła

- **Given** zaimportowana playlista należy do powiązanego konta użytkownika.
- **When** użytkownik włącza automatyczną synchronizację albo uruchamia ją ręcznie.
- **Then** zmiany wykonane w `music-map` aktualizują źródło po zapisanym ID, a zmiany z platformy aktualizują bank bez tworzenia nowej playlisty; w konflikcie wygrywa platforma źródłowa.

## Wymagania Funkcjonalne

### Konto i bank playlist

- FR-001: Użytkownik może utworzyć konto `music-map` i zalogować się przez e-mail lub zewnętrznego dostawcę tożsamości. Priorytet: musi-być
  > Sokrates: Rozważono ryzyko utworzenia dwóch kont przez logowanie e-mailem i przez zewnętrznego dostawcę tożsamości z tym samym adresem. Rozwiązanie: jeden adres e-mail odpowiada jednemu kontu `music-map`, a metody logowania zostają powiązane.
- FR-002: Zalogowany użytkownik może przeglądać playlisty zapisane w swoim niezależnym banku `music-map` oraz relacje źródło–eksport między powiązanymi playlistami z różnych platform. Priorytet: musi-być
  > Sokrates: Rozważono niejasną widoczność playlist przechowywanych w banku. Rozwiązanie: playlistę widzi wyłącznie jej właściciel.
- FR-003: Zalogowany użytkownik może utworzyć nową playlistę od zera w banku `music-map`. Priorytet: miło-mieć
  > Sokrates: Rozważono, że ręczne tworzenie i walidacja pustych playlist rozszerzają pierwszy przepływ ponad konieczny import i eksport. Rozwiązanie: tworzenie playlisty od zera przeniesiono do funkcji `miło-mieć`.
- FR-004: Zalogowany użytkownik może zaimportować do banku `music-map` playlistę z linku udostępniania jednej z obsługiwanych platform streamingowych, jeżeli platforma pozwala odczytać jej zawartość; w przeciwnym razie otrzymuje przyczynę odmowy oraz wskazanie, aby zmienić widoczność playlisty albo powiązać konto źródłowe. Priorytet: musi-być
  > Sokrates: Rozważono, że share link nie omija ustawień prywatności platformy i może nie pozwolić na odczyt playlisty. Rozwiązanie: import jest wykonywany tylko dla dostępnej zawartości; przy odmowie użytkownik widzi przyczynę oraz wskazanie zmiany widoczności lub powiązania konta.
- FR-005: Zalogowany użytkownik może edytować playlistę przechowywaną w banku `music-map`. Jeżeli importowana playlista źródłowa należy do jego powiązanego konta platformy, może włączyć dwukierunkową automatyczną synchronizację albo uruchomić ją ręcznie. Zmiany w banku aktualizują źródło po zapisanym ID, a zmiany wykryte na platformie aktualizują bank; przy wyłączonej synchronizacji automatycznej zmiany zewnętrzne są ignorowane do czasu użycia przycisku. W razie konfliktu wersja platformy zewnętrznej jest nadrzędna. Priorytet: musi-być
  > Sokrates: Rozważono, że edycja niezależnej kopii może rozminąć ją ze źródłem albo tworzyć zbędne kopie. Rozwiązanie: użytkownik wybiera synchronizację automatyczną lub ręczną, a aktualizacja używa zapisanego ID istniejącej playlisty.

### Integracje i eksport

- FR-006: Zalogowany użytkownik może powiązać lub odłączyć swoje konto jednej z obsługiwanych platform streamingowych od konta `music-map`; odłączenie konta automatycznie wyłącza synchronizację wszystkich playlist powiązanych z tym kontem. Priorytet: musi-być
  > Sokrates: Rozważono pozostawienie aktywnej synchronizacji mimo odłączenia konta platformy. Rozwiązanie: odłączenie konta automatycznie wyłącza synchronizację wszystkich powiązanych z nim playlist.
- FR-007: Użytkownik może wybrać jedną z dwóch obsługiwanych platform streamingowych jako cel eksportu playlisty, z wyjątkiem tej samej pary platforma–konto, z której pochodzi playlista źródłowa. Priorytet: musi-być
  > Sokrates: Rozważono, że eksport do dokładnie tego samego źródła nie wnosi wartości i powiela synchronizację. Rozwiązanie: ta sama para platforma–konto nie jest dostępna jako cel eksportu.
- FR-008: Użytkownik może przed eksportem zobaczyć dopasowane, błędnie dopasowane i niedostępne utwory. Brakującą pozycję może usunąć albo pozostawić w banku, przy czym nie trafia ona do eksportu. Błędne dopasowanie może usunąć albo pozostawić, a pozostawione trafia do eksportu jako wskazany utwór docelowy. Następnie użytkownik świadomie potwierdza rozpoczęcie operacji. Priorytet: musi-być
  > Sokrates: Rozważono pomieszanie utworów nieistniejących z dopasowanymi do niewłaściwej piosenki. Rozwiązanie: brakujący utwór jest pomijany, a pozostawione błędne dopasowanie eksportuje się jako pokazany utwór docelowy; ręczny zamiennik pozostaje `miło-mieć`.
- FR-009: Użytkownik z powiązanym kontem platformy może utworzyć eksportowaną playlistę bezpośrednio na swoim koncie albo zaktualizować jej wcześniej zapisaną kopię, rozpoznawaną wyłącznie po ID playlisty nadanym przez platformę. Nowa playlista nie jest widoczna publicznie na profilu; na platformie wideo jest dostępna przez link, a na platformie audio pozostaje prywatna. Priorytet: musi-być
  > Sokrates: Rozważono niepotrzebne ujawnienie kolekcji przez nieokreśloną widoczność. Rozwiązanie: playlista na powiązanym koncie nie jest widoczna publicznie na profilu; na platformie wideo jest dostępna przez link, a na platformie audio pozostaje prywatna.
- FR-010: Użytkownik bez powiązanego konta może otrzymać playlistę utworzoną na koncie technicznym `music-map` albo zaktualizować jej wcześniej zapisaną kopię, rozpoznawaną wyłącznie po ID playlisty nadanym przez platformę. Playlista jest dostępna przez stały link i niewidoczna publicznie na profilu, zgodnie z możliwościami platformy. Wynik zawiera link, instrukcję, informację o właścicielu i miejscu edycji. Priorytet: musi-być
  > Sokrates: Rozważono, że jedna z platform nie obsługuje automatycznego generowania czasowych zaproszeń. Rozwiązanie: użytkownik otrzymuje stały link, a playlista pozostaje niewidoczna publicznie na profilu zgodnie z możliwościami platformy.
- FR-011: Użytkownik może zobaczyć status eksportu jako `W trakcie przenoszenia`, `Przeniesiona — na Twoim koncie`, `Przeniesiona — zarządzana przez music-map`, `Nie przeniesiono` albo `Nie udało się dokończyć przenoszenia`, wraz z przyczyną niepowodzenia. Gdy platforma przerwie rozpoczętą operację, użytkownik otrzymuje komunikat: „Platforma przerwała operację. Spróbuj ponownie za kilka minut — zaktualizujemy tę samą playlistę, bez tworzenia kolejnej kopii” i może ponowić eksport. Priorytet: musi-być
  > Sokrates: Rozważono przerwanie eksportu po dodaniu tylko części utworów. Rozwiązanie: użytkownik widzi status `Nie udało się dokończyć przenoszenia`, przyczynę i możliwość ponowienia; kolejna próba aktualizuje tę samą playlistę po ID.

### Funkcje dodatkowe

- FR-012: Użytkownik może zobaczyć wizualną mapę przedstawiającą autorów oraz powiązane z nimi utwory. Priorytet: miło-mieć
  > Sokrates: Rozważono, że pojęcie „mapy muzyki” nie określa relacji do pokazania. Rozwiązanie: pierwszy wariant mapy przedstawia autorów oraz powiązane z nimi utwory.
- FR-013: Użytkownik może przy niedostępnym lub błędnie dopasowanym utworze zobaczyć sugestię „Sprawdź też artystę X”, przejrzeć popularne utwory powiązanego artysty pochodzące z zewnętrznego katalogu relacji artystów i popularności utworów oraz ręcznie wybrać zamiennik. Priorytet: miło-mieć
  > Sokrates: Rozważono zatarcie pochodzenia rekomendacji przez mieszanie źródeł danych. Rozwiązanie: rekomendacje używają wyłącznie powiązanych artystów i ich popularnych utworów z jednego zewnętrznego katalogu, a dane źródłowe pozostają tylko do odczytu.

### Spójność i cykl życia

- FR-014: Po synchronizacji playlisty system porównuje ją z powiązanymi playlistami źródło–eksport. Jeżeli wykryje różnice po drugiej stronie, oznacza odpowiednią playlistę jako `Nieaktualna`, powiadamia użytkownika komunikatem wskazującym platformę zmiany i pozwala otworzyć widok różnic. Z widoku różnic użytkownik może ponowić eksport ze źródła; system aktualizuje powiązaną playlistę po zapisanym ID, a po sukcesie oznacza obie strony jako aktualne. Priorytet: musi-być
  > Sokrates: Rozważono, że samo pokazanie różnic nie przywraca zgodności. Rozwiązanie: użytkownik ponawia eksport do nieaktualnej playlisty, która jest aktualizowana po zapisanym ID; po sukcesie obie strony są aktualne.
- FR-015: Zalogowany użytkownik może usunąć konto `music-map` po zobaczeniu listy skutków i wyraźnym potwierdzeniu. Operacja usuwa jego bank playlist, relacje, historię synchronizacji, dane umożliwiające dostęp do powiązanych usług i playlisty zarządzane na koncie technicznym `music-map`, ale pozostawia playlisty utworzone na jego powiązanych kontach platform streamingowych. Priorytet: musi-być
  > Sokrates: Rozważono ryzyko usunięcia playlist należących do użytkownika albo pozostawienia jego danych na koncie technicznym. Rozwiązanie: `music-map` usuwa własne dane i zarządzane kopie oraz unieważnia integracje, ale pozostawia playlisty na kontach użytkownika.

## Wymagania Niefunkcjonalne

- NFR-001 — Pojemność: MVP niezawodnie obsługuje playlisty zawierające do 50 utworów w całym przepływie importu, sprawdzania dostępności, synchronizacji i eksportu.
- NFR-002 — Responsywność: dla playlisty do 50 utworów celem jest przygotowanie podglądu dostępności i dopasowania w około 30 sekund, a aktywne oczekiwanie użytkownika nie przekracza 60 sekund. Po przekroczeniu minuty użytkownik może opuścić ekran bez anulowania operacji i otrzymuje powiadomienie po jej zakończeniu.
- NFR-003 — Bezpieczeństwo integracji: dane uwierzytelniające powiązanych platform streamingowych pozostają poufne, nie pojawiają się w logach dostępnych operatorowi, nie przyznają aplikacji uprawnień wykraczających poza jej funkcje i przestają umożliwiać dostęp po odłączeniu integracji lub usunięciu konta.
- NFR-004 — Świeżość synchronizacji: przy włączonej synchronizacji zmiana na platformie jest wykrywana najpóźniej w ciągu 4 godzin. Logowanie może uruchomić dodatkową kontrolę, jeżeli od poprzedniej minęło co najmniej 15 minut, a użytkownik może niezależnie zażądać synchronizacji ręcznej.
- NFR-005 — Odtwarzalność danych: maksymalna dopuszczalna utrata zmian w banku playlist wynosi 24 godziny, a usługa i dane powinny zostać odtworzone w ciągu 24 godzin.

## Logika Biznesowa

`music-map` traktuje każdą playlistę platformową jako osobny byt z jednym nadrzędnym źródłem, wykrywa różnice między powiązanymi kopiami i przywraca ich zgodność przez ponowny eksport do playlisty wskazanej zapisanym ID.

- BR-001 — Zmiany zewnętrzne playlisty źródłowej są pobierane automatycznie tylko przy włączonej synchronizacji. Przy wyłączonej synchronizacji wymagają użycia przycisku; bez akcji użytkownika `music-map` je ignoruje.
- BR-002 — W konflikcie między zmianami w banku `music-map` a zmianami na platformie źródłowej danej playlisty nadrzędna jest wersja tej platformy, która aktualizuje kopię przechowywaną w banku.
- BR-003 — Każda playlista ma jedno nadrzędne źródło i własne ID nadane przez platformę. Playlisty utworzone na różnych platformach są osobnymi bytami połączonymi relacją źródło–eksport, nawet gdy zawierają identyczny zestaw utworów; relacja służy do nawigacji i porównywania różnic.
- BR-004 — Nieaktualna playlista powiązana odzyskuje zgodność przez ponowny eksport z jej źródła i aktualizację po zapisanym ID, bez tworzenia nowej playlisty i bez osobnego scalania.
- BR-005 — Usunięcie konta `music-map` nie usuwa playlist należących do użytkownika na powiązanych platformach streamingowych. Usuwa natomiast dane aplikacji, dane umożliwiające dostęp do powiązanych usług oraz playlisty utworzone dla niego na koncie technicznym `music-map`, po uprzednim pokazaniu skutków i uzyskaniu wyraźnego potwierdzenia.

## Kontrola Dostępu

`music-map` ma publiczną stronę prezentującą produkt, ale bank playlist i pozostałe funkcje są dostępne wyłącznie po zalogowaniu. Użytkownik tworzy osobne konto aplikacji przez e-mail lub zewnętrznego dostawcę tożsamości. Może opcjonalnie powiązać konta dwóch obsługiwanych platform streamingowych i kolejnych usług muzycznych. Wszyscy zalogowani użytkownicy mają takie same uprawnienia.

## Cele Niezwiązane z Projektem

- Ręczne tworzenie playlist od zera.
- Wizualna mapa autorów i ich utworów.
- Zamienniki oparte na zewnętrznym katalogu relacji artystów i popularności utworów.
- Obsługa innych platform muzycznych niż dwie objęte zakresem MVP.
- Gwarantowana obsługa playlist zawierających więcej niż 50 utworów.
- Udostępnianie banku playlist innym użytkownikom i rozbudowane role.
- Jednorazowe wzbogacenie zewnętrznych danych o daty premier.
- Paywall, subskrypcje i rozliczenia.

## Otwarte Pytania

Brak otwartych pytań blokujących przygotowanie PRD.

## Kontrola jakości

Status: zaakceptowana. Kontrola dostępu, jednozdaniowa logika biznesowa, artefakt z checkpointem, potwierdzenie kosztu harmonogramu oraz cele niezwiązane z projektem są obecne. Kontrola zachowanego zachowania nie dotyczy projektu greenfield. Nie pozostały żadne luki soft-gate.

## Dalej: stos technologiczny

- W PRD określenie „platforma wideo” oznacza YouTube, a „platforma audio” oznacza Spotify.
- W PRD „zewnętrzny dostawca tożsamości” oznacza Google OAuth.
- W PRD „zewnętrzny katalog relacji artystów i popularności utworów” oznacza ListenBrainz; MusicBrainz i lokalne przejścia odsłuchów nie są źródłem rekomendacji.
- baza danych już jest na tej maszynie w postgresie dockerowym
- Spotify Web API nie udostępnia zarządzania kontrolą dostępu ani dodawania i usuwania współpracowników; 7-dniowe linki do prywatnych playlist są generowane w kliencie Spotify, bez udokumentowanego endpointu Web API do ich tworzenia lub odświeżania. Źródło: https://developer.spotify.com/documentation/web-api/concepts/playlists
- `music-map` korzysta z tabel ListenBrainz w bazie `music_cache` wyłącznie do odczytu i nie modyfikuje ich rekordów; lokalne tabele przejść odsłuchów należą do innego projektu i nie są źródłem rekomendacji.
- Obecna tabela `music_cache_artist_songs` zawiera ranking utworów według liczby unikalnych słuchaczy w oknie importu, ale nie zawiera dat premier utworów.
- Tokeny OAuth mają być szyfrowane w bazie, pomijane w logach i unieważniane po odłączeniu integracji lub usunięciu konta.
- Baza banku playlist ma otrzymywać codzienny szyfrowany backup spełniający wymagania RPO i RTO wynoszące po 24 godziny.
